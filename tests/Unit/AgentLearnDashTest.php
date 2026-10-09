<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentLearnDashTest extends TestCase
{
    private function boot(array $missing = []): void
    {
        $GLOBALS['ld_missing_functions'] = $missing;
        require __DIR__.'/../Support/wordpress-learndash.php';
    }

    private function call(string $name, array $args = []): array
    {
        return \Multioto_Agent_LearnDash::call('ld_'.$name, $args);
    }

    private function selector(string $kind = 'course', int $user = 7): array
    {
        return ['user_id' => $user, 'kind' => $kind, 'target_id' => $kind === 'course' ? 10 : 20];
    }

    private function read(array $selector = []): array
    {
        return $this->call('membership_get', $selector ?: $this->selector());
    }

    private function prepare(string $action = 'add', array $selector = []): array
    {
        $selector = $selector ?: $this->selector();

        return $this->call('membership_prepare', $selector + ['action' => $action, 'expected' => $this->read($selector)['snapshot']]);
    }

    private function apply(array $prepared, array $selector = []): array
    {
        return $this->call('membership_apply', ($selector ?: $this->selector()) + ['expected' => $prepared['expected'], 'prepared' => $prepared['prepared']]);
    }

    private function undo(array $applied, array $selector = []): array
    {
        return $this->call('membership_revert', ($selector ?: $this->selector()) + ['expected' => $applied['after'], 'restore' => $applied['before']]);
    }

    private function refused(callable $callback, ?string $message = null): \Multioto_Agent_Rpc_Error
    {
        try {
            $callback();
            self::fail('The unsafe LearnDash operation was accepted.');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame(-32602, $error->getCode());
            if ($message !== null) {
                self::assertStringContainsString($message, $error->getMessage());
            }

            return $error;
        }
    }

    private function metadata(int $user, string $key): array
    {
        return get_user_meta($user, $key, false);
    }

    public function test_enrollment_is_previewed_without_writes_then_applied_and_undone_without_resetting_progress(): void
    {
        $this->boot();
        $GLOBALS['ld']['meta'][7]['unrelated'] = ['keep'];
        $before = $GLOBALS['ld']['meta'];
        $progress = $GLOBALS['ld']['progress'];
        $attempts = $GLOBALS['ld']['attempts'];
        $prepared = $this->prepare();
        self::assertTrue($prepared['changed']);
        self::assertFalse($prepared['before']['direct_member']);
        self::assertTrue($prepared['after']['direct_member']);
        self::assertTrue($prepared['after']['effective_access']);
        self::assertNull($prepared['after']['access_from']);
        self::assertSame($before, $GLOBALS['ld']['meta']);
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        self::assertSame([], $GLOBALS['ld']['options']);
        $beforeApply = time();
        $applied = $this->apply($prepared);
        self::assertTrue($applied['changed']);
        self::assertGreaterThanOrEqual($beforeApply, (int) $this->metadata(7, 'course_10_access_from')[0]);
        self::assertSame([['course', 7, 10, false]], $GLOBALS['ld']['native_calls']);
        self::assertTrue($this->undo($applied)['changed']);
        self::assertSame($before, $GLOBALS['ld']['meta']);
        self::assertSame($progress, $GLOBALS['ld']['progress']);
        self::assertSame($attempts, $GLOBALS['ld']['attempts']);
        self::assertArrayNotHasKey('_multioto_ld_write_7', $GLOBALS['ld']['options']);
    }

    public function test_removal_and_undo_restore_the_original_direct_access_timestamp_without_restarting_drip_dates(): void
    {
        $this->boot();
        $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1609459200'];
        $prepared = $this->prepare('remove');
        self::assertSame(1609459200, $prepared['before']['access_from']);
        self::assertNull($prepared['after']['access_from']);
        self::assertFalse($prepared['after']['effective_access']);
        $applied = $this->apply($prepared);
        self::assertSame([], $this->metadata(7, 'course_10_access_from'));
        $this->undo($applied);
        self::assertSame(['1609459200'], $this->metadata(7, 'course_10_access_from'));
        self::assertSame([['course', 7, 10, true], ['course', 7, 10, false]], $GLOBALS['ld']['native_calls']);
    }

    public function test_removing_direct_membership_does_not_claim_to_remove_inherited_or_open_course_access(): void
    {
        $this->boot();
        foreach (['group', 'open'] as $source) {
            ld_fixture_reset();
            $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1609459200'];
            if ($source === 'group') {
                $GLOBALS['ld']['meta'][7]['learndash_group_users_20'] = ['20'];
            } else {
                $GLOBALS['ld']['modes'][10] = 'open';
            }
            $prepared = $this->prepare('remove');
            self::assertTrue($prepared['after']['effective_access']);
            self::assertFalse($prepared['after']['direct_member']);
            self::assertStringContainsString('תישאר', implode(' ', $prepared['notes']));
            $this->apply($prepared);
            self::assertTrue($this->read()['state']['effective_access']);
            if ($source === 'group') {
                self::assertSame(['20'], $this->metadata(7, 'learndash_group_users_20'));
            }
            self::assertSame([['course', 7, 10, true]], $GLOBALS['ld']['native_calls']);
        }
    }

    public function test_group_addition_and_removal_preview_affected_courses_and_undo_only_group_membership(): void
    {
        $this->boot();
        foreach (['add', 'remove'] as $action) {
            ld_fixture_reset();
            $selector = $this->selector('group');
            $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1609459200'];
            $GLOBALS['ld']['meta'][7]['unrelated'] = ['keep'];
            if ($action === 'remove') {
                $GLOBALS['ld']['meta'][7]['learndash_group_users_20'] = ['20'];
            }
            $before = $GLOBALS['ld']['meta'][7];
            $prepared = $this->prepare($action, $selector);
            self::assertSame([10, 11], array_column($prepared['impacts'], 'course_id'));
            self::assertTrue($prepared['impacts'][0]['after_access']);
            self::assertSame($action === 'add', $prepared['impacts'][1]['after_access']);
            $applied = $this->apply($prepared, $selector);
            self::assertSame($action === 'add' ? ['20'] : [], $this->metadata(7, 'learndash_group_users_20'));
            self::assertSame(['1609459200'], $this->metadata(7, 'course_10_access_from'));
            $this->undo($applied, $selector);
            self::assertEquals($before, $GLOBALS['ld']['meta'][7]);
            self::assertSame(['group', 'group'], array_column($GLOBALS['ld']['native_calls'], 0));
            self::assertSame([], $GLOBALS['ld']['caps'][7] ?? []);
            self::assertSame([['score' => 82, 'completed_at' => 1700000000]], $GLOBALS['ld']['attempts']['7:102']);
        }
    }

    public function test_apply_and_undo_retries_are_idempotent_and_reverted_approval_cannot_reenroll(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        $applied = $this->apply($prepared);
        $again = $this->apply($prepared);
        self::assertTrue($again['already_applied']);
        self::assertSame($applied['after'], $again['after']);
        self::assertCount(1, $GLOBALS['ld']['native_calls']);
        $this->undo($applied);
        self::assertTrue($this->undo($applied)['already_reverted']);
        self::assertCount(2, $GLOBALS['ld']['native_calls']);
        $this->refused(fn () => $this->apply($prepared));
        self::assertCount(2, $GLOBALS['ld']['native_calls']);
        self::assertSame([], $this->metadata(7, 'course_10_access_from'));
    }

    public function test_noop_add_and_remove_do_not_touch_native_api_or_dates(): void
    {
        $this->boot();
        self::assertFalse($this->apply($this->prepare('remove'))['changed']);
        $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1609459200'];
        self::assertFalse($this->apply($this->prepare('add'))['changed']);
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        self::assertSame(['1609459200'], $this->metadata(7, 'course_10_access_from'));
    }

    public function test_policy_membership_group_mapping_and_identity_changes_invalidate_the_full_approval_snapshot(): void
    {
        $this->boot();
        foreach (['direct', 'group', 'group_courses', 'policy', 'title', 'learner'] as $change) {
            ld_fixture_reset();
            $GLOBALS['ld']['meta'][7]['learndash_group_users_20'] = ['20'];
            $prepared = $this->prepare();
            match ($change) {
                'direct' => $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1700000000'],
                'group' => $GLOBALS['ld']['meta'][7]['learndash_group_users_20'] = [],
                'group_courses' => $GLOBALS['ld']['group_courses'][20] = [11],
                'policy' => $GLOBALS['ld']['settings'][10] = ['sfwd-courses_expire_access' => 'on'],
                'title' => $GLOBALS['ld']['posts'][10]->post_title = 'Renamed course',
                'learner' => $GLOBALS['ld']['users'][7]->display_name = 'Renamed student',
            };
            $before = $GLOBALS['ld']['meta'];
            $this->refused(fn () => $this->apply($prepared), 'השתנו');
            self::assertSame($before, $GLOBALS['ld']['meta'], $change);
            self::assertSame([], $GLOBALS['ld']['native_calls'], $change);
        }
    }

    public function test_undo_refuses_changed_access_and_preserves_the_intervening_manual_change(): void
    {
        $this->boot();
        $applied = $this->apply($this->prepare());
        $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1700000000'];
        $this->refused(fn () => $this->undo($applied), 'השתנו');
        self::assertSame(['1700000000'], $this->metadata(7, 'course_10_access_from'));
        self::assertCount(1, $GLOBALS['ld']['native_calls']);
    }

    public function test_unrelated_user_metadata_progress_and_quiz_updates_do_not_invalidate_membership_undo(): void
    {
        $this->boot();
        $applied = $this->apply($this->prepare());
        $GLOBALS['ld']['meta'][7]['profile_note'] = ['New note'];
        $GLOBALS['ld']['progress']['7:10']['completed'] = 2;
        $GLOBALS['ld']['attempts']['7:102'][] = ['score' => 94, 'completed_at' => 1750000000];
        $this->undo($applied);
        self::assertSame(['New note'], $this->metadata(7, 'profile_note'));
        self::assertSame(2, $GLOBALS['ld']['progress']['7:10']['completed']);
        self::assertCount(2, $GLOBALS['ld']['attempts']['7:102']);
    }

    public function test_sealed_approval_is_bound_to_user_target_and_site_and_refuses_tampered_receipts(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        foreach (['user_id' => 8, 'target_id' => 11] as $key => $value) {
            $selector = $this->selector();
            $selector[$key] = $value;
            $this->refused(fn () => $this->apply($prepared, $selector));
        }
        $GLOBALS['ld']['home'] = 'https://another.example.test/';
        $this->refused(fn () => $this->apply($prepared));
        $GLOBALS['ld']['home'] = 'https://learning.example.test/';
        foreach (['token', 'version'] as $key) {
            $tampered = $prepared;
            $original = $tampered['prepared'][$key];
            $tampered['prepared'][$key] = ($original[0] === 'A' ? 'B' : 'A').substr($original, 1);
            $this->refused(fn () => $this->apply($tampered));
        }
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        $this->apply($prepared);
        self::assertCount(1, $GLOBALS['ld']['native_calls']);
    }

    public function test_snapshot_from_a_different_approved_change_cannot_be_used_as_an_undo_receipt(): void
    {
        $this->boot();
        $first = $this->apply($this->prepare());
        $other = $this->selector('course', 8);
        $second = $this->apply($this->prepare('add', $other), $other);
        $forged = $first;
        $forged['before'] = $second['before'];
        $this->refused(fn () => $this->undo($forged));
        self::assertCount(2, $GLOBALS['ld']['native_calls']);
        self::assertNotEmpty($this->metadata(7, 'course_10_access_from'));
    }

    public function test_administrators_super_administrators_and_users_promoted_after_approval_are_protected(): void
    {
        $this->boot();
        foreach (['manage_options', 'edit_users', 'promote_users', 'delete_users', 'manage_network'] as $cap) {
            $GLOBALS['ld']['caps'][7] = [$cap];
            $this->refused(fn () => $this->read());
        }
        $GLOBALS['ld']['caps'][7] = [];
        $GLOBALS['ld']['super_admins'] = [7];
        $this->refused(fn () => $this->read());
        $GLOBALS['ld']['super_admins'] = [];
        $prepared = $this->prepare();
        $GLOBALS['ld']['caps'][7] = ['manage_options'];
        $this->refused(fn () => $this->apply($prepared));
        self::assertSame([], $GLOBALS['ld']['native_calls']);
    }

    public static function missingDependencies(): array
    {
        return array_map(static fn (string $function): array => [$function], [
            'learndash_use_legacy_course_access_list', 'learndash_get_setting',
            'ld_course_access_expires_on', 'sfwd_lms_has_access', 'ld_update_course_access',
            'learndash_get_course_expired_access_from_meta',
        ]);
    }

    #[DataProvider('missingDependencies')]
    public function test_missing_native_dependency_makes_membership_readonly_and_refuses_writes(string $function): void
    {
        $this->boot([$function]);
        $read = $this->read();
        self::assertFalse($read['writable']);
        self::assertNotEmpty($read['reason']);
        $this->refused(fn () => $this->prepare());
        self::assertSame([], $GLOBALS['ld']['native_calls']);
    }

    public function test_inactive_plugin_returns_unavailable_capabilities_without_fabricated_course_results(): void
    {
        $this->boot(['LEARNDASH_VERSION', 'learndash_get_setting', 'ld_update_course_access']);
        $capabilities = $this->call('capabilities');
        self::assertFalse($capabilities['active']);
        self::assertFalse($capabilities['course_membership']['available']);
        $this->refused(fn () => $this->call('courses_list'));
    }

    public function test_legacy_storage_unknown_policy_enabled_expiration_and_malformed_rows_are_readonly(): void
    {
        $this->boot();
        foreach (['legacy', 'expiry_policy', 'unknown_flag', 'expiry_date', 'unknown_mode', 'duplicate_rows', 'bad_timestamp', 'external_access'] as $case) {
            ld_fixture_reset();
            match ($case) {
                'legacy' => $GLOBALS['ld']['legacy'] = true,
                'expiry_policy' => $GLOBALS['ld']['settings'][10] = ['sfwd-courses_expire_access' => 'on'],
                'unknown_flag' => $GLOBALS['ld']['settings'][10] = ['sfwd-courses_expire_access' => 'maybe'],
                'expiry_date' => $GLOBALS['ld']['expiry']['7:10'] = time() + 86400,
                'unknown_mode' => $GLOBALS['ld']['modes'][10] = 'external-membership-policy',
                'duplicate_rows' => $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1609459200', '1700000000'],
                'bad_timestamp' => $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['not-a-date'],
                'external_access' => $GLOBALS['ld']['effective']['7:10'] = true,
            };
            self::assertFalse($this->read()['writable'], $case);
            $this->refused(fn () => $this->prepare());
            self::assertSame([], $GLOBALS['ld']['native_calls'], $case);
        }
    }

    public function test_expired_native_marker_still_blocks_reenrollment_after_expiration_is_disabled(): void
    {
        $this->boot();
        $GLOBALS['ld']['settings'][10] = ['sfwd-courses_expire_access' => 'off'];
        $GLOBALS['ld']['expired'][10] = ['7'];
        self::assertFalse($this->read()['writable']);
        $this->refused(fn () => $this->prepare());
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        $GLOBALS['ld']['expired'][10] = [8];
        self::assertTrue($this->read()['writable']);
        self::assertTrue($this->apply($this->prepare())['changed']);
    }

    public function test_global_user_bound_precedes_the_coursewide_expired_lookup(): void
    {
        $this->boot();
        $GLOBALS['ld']['population'] = range(1, 1000);
        self::assertTrue($this->read()['writable']);
        self::assertSame([10], $GLOBALS['ld']['expired_calls']);
        $query = $GLOBALS['ld']['user_queries'][0];
        self::assertSame(0, $query['blog_id']);
        self::assertSame(1001, $query['number']);
        self::assertSame('ID', $query['fields']);
        foreach ([range(1, 1001), false] as $population) {
            $GLOBALS['ld']['population'] = $population;
            $GLOBALS['ld']['expired_calls'] = [];
            self::assertFalse($this->read()['writable']);
            $this->refused(fn () => $this->prepare());
            self::assertSame([], $GLOBALS['ld']['expired_calls']);
        }
        self::assertSame([], $GLOBALS['ld']['native_calls']);
    }

    public function test_group_changes_refuse_affected_course_expiration_and_unknown_effective_access(): void
    {
        $this->boot();
        foreach (['policy', 'effective'] as $case) {
            ld_fixture_reset();
            if ($case === 'policy') {
                $GLOBALS['ld']['settings'][11] = ['sfwd-courses_expire_access' => 'on'];
            } else {
                $GLOBALS['ld']['effective']['7:11'] = true;
            }
            $selector = $this->selector('group');
            self::assertFalse($this->read($selector)['writable']);
            $this->refused(fn () => $this->prepare('add', $selector));
            self::assertSame([], $GLOBALS['ld']['native_calls']);
        }
    }

    public function test_multisite_blocks_student_specific_read_and_write_but_retains_catalogue_and_hierarchy(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        $GLOBALS['ld']['meta'][7]['learndash_group_users_20'] = ['20'];
        $GLOBALS['ld']['multisite'] = true;
        $GLOBALS['ld']['expired_calls'] = [];
        $GLOBALS['ld']['user_queries'] = [];
        $this->refused(fn () => $this->read());
        $this->refused(fn () => $this->read($this->selector('group')));
        $this->refused(fn () => $this->call('student_course_get', ['user_id' => 7, 'course_id' => 10]));
        $this->refused(fn () => $this->apply($prepared));
        $group = $this->call('group_get', ['group_id' => 20]);
        self::assertFalse($group['members_available']);
        self::assertSame([], $group['members']);
        self::assertCount(2, $this->call('courses_list')['courses']);
        self::assertCount(1, $this->call('course_get', ['course_id' => 10])['hierarchy']['lessons']);
        self::assertSame([], $GLOBALS['ld']['expired_calls']);
        self::assertSame([], $GLOBALS['ld']['user_queries']);
        self::assertSame([], $GLOBALS['ld']['native_calls']);
    }

    public function test_live_lock_prevents_writes_and_expired_lock_is_reclaimed(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        $other = ['owner' => 'another-worker', 'expires' => time() + 300];
        $GLOBALS['ld']['options']['_multioto_ld_write_7'] = $other;
        $this->refused(fn () => $this->apply($prepared));
        self::assertSame($other, $GLOBALS['ld']['options']['_multioto_ld_write_7']);
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        $GLOBALS['ld']['options']['_multioto_ld_write_7']['expires'] = time() - 1;
        self::assertTrue($this->apply($prepared)['changed']);
        self::assertArrayNotHasKey('_multioto_ld_write_7', $GLOBALS['ld']['options']);
    }

    public function test_replaced_or_expired_lock_after_native_write_never_rolls_back_under_a_new_owner(): void
    {
        $this->boot();
        foreach (['replacement', 'expiration'] as $case) {
            ld_fixture_reset();
            $prepared = $this->prepare();
            $replacement = ['owner' => 'replacement-worker', 'expires' => time() + 300];
            $GLOBALS['ld']['after_native'] = static function () use ($case, $replacement): void {
                if ($case === 'replacement') {
                    $GLOBALS['ld']['options']['_multioto_ld_write_7'] = $replacement;
                } else {
                    $GLOBALS['ld']['options']['_multioto_ld_write_7']['expires'] = time() - 1;
                }
            };
            $this->refused(fn () => $this->apply($prepared), 'לבדוק');
            self::assertCount(1, $GLOBALS['ld']['native_calls']);
            self::assertNotEmpty($this->metadata(7, 'course_10_access_from'));
            self::assertArrayHasKey('_multioto_ld_write_7', $GLOBALS['ld']['options']);
            if ($case === 'replacement') {
                self::assertSame($replacement, $GLOBALS['ld']['options']['_multioto_ld_write_7']);
            }
        }
    }

    public function test_native_write_then_throw_requires_manual_review_instead_of_reporting_rollback_success(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        $GLOBALS['ld']['after_native'] = static function (): void {
            throw new \RuntimeException('A third-party enrollment hook failed after persisting.');
        };
        $error = $this->refused(fn () => $this->apply($prepared), 'בדיקה בלוח הבקרה');
        self::assertStringNotContainsString('הקודמת שוחזרה', $error->getMessage());
        self::assertNotEmpty($this->metadata(7, 'course_10_access_from'));
        self::assertCount(1, $GLOBALS['ld']['native_calls']);
        self::assertSame([], $GLOBALS['ld']['options']);
    }

    public function test_native_noop_never_reports_success_or_issues_a_false_undo_receipt(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        $GLOBALS['ld']['native_noop'] = true;
        $this->refused(fn () => $this->apply($prepared));
        self::assertSame([], $this->metadata(7, 'course_10_access_from'));
        self::assertSame([], $GLOBALS['ld']['options']);
    }

    public function test_failed_apply_journal_is_compensated_but_external_metadata_is_never_overwritten(): void
    {
        $this->boot();
        $prepared = $this->prepare();
        $GLOBALS['ld']['fail_journal_add'] = true;
        $this->refused(fn () => $this->apply($prepared), 'הקודמת שוחזרה');
        self::assertSame([], $this->metadata(7, 'course_10_access_from'));
        self::assertCount(2, $GLOBALS['ld']['native_calls']);
        ld_fixture_reset();
        $prepared = $this->prepare();
        $GLOBALS['ld']['fail_journal_add'] = true;
        $GLOBALS['ld']['before_add_option'] = static function ($key): void {
            if (str_starts_with($key, 'multioto_ld_change_')) {
                $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1700000000'];
            }
        };
        $this->refused(fn () => $this->apply($prepared), 'השינוי החיצוני לא נדרס');
        self::assertSame(['1700000000'], $this->metadata(7, 'course_10_access_from'));
        self::assertCount(1, $GLOBALS['ld']['native_calls']);
    }

    public function test_failed_undo_journal_restores_membership_to_the_state_before_the_attempt(): void
    {
        $this->boot();
        $applied = $this->apply($this->prepare());
        $actual = $this->metadata(7, 'course_10_access_from');
        $GLOBALS['ld']['fail_journal_update'] = true;
        $this->refused(fn () => $this->undo($applied), 'השחזור לא הושלם');
        self::assertSame($actual, $this->metadata(7, 'course_10_access_from'));
        self::assertCount(3, $GLOBALS['ld']['native_calls']);
        $GLOBALS['ld']['fail_journal_update'] = false;
        self::assertTrue($this->undo($applied)['changed']);
        self::assertSame([], $this->metadata(7, 'course_10_access_from'));
    }

    public function test_failed_exact_timestamp_restore_is_reported_for_manual_review(): void
    {
        $this->boot();
        $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1609459200'];
        $applied = $this->apply($this->prepare('remove'));
        $GLOBALS['ld']['fail_meta_update'] = true;
        $error = $this->refused(fn () => $this->undo($applied), 'בדיקה בלוח הבקרה');
        self::assertStringNotContainsString('החברות נשמרה במצב', $error->getMessage());
        self::assertNotSame(['1609459200'], $this->metadata(7, 'course_10_access_from'));
    }

    public function test_catalogue_pagination_is_bounded_stable_searchable_and_does_not_expose_trashed_courses(): void
    {
        $this->boot();
        $GLOBALS['ld']['posts'][12] = (object) ['ID' => 12, 'post_type' => 'sfwd-courses', 'post_title' => 'Trashed course', 'post_status' => 'trash'];
        $first = $this->call('courses_list', ['limit' => 1]);
        self::assertSame([10], array_column($first['courses'], 'id'));
        self::assertTrue($first['has_more']);
        self::assertSame(2, $GLOBALS['ld']['post_query']['numberposts']);
        $second = $this->call('courses_list', ['limit' => 1, 'page' => 2]);
        self::assertSame([11], array_column($second['courses'], 'id'));
        self::assertFalse($second['has_more']);
        self::assertSame([11], array_column($this->call('courses_list', ['search' => 'Course B'])['courses'], 'id'));
        $this->refused(fn () => $this->call('courses_list', ['limit' => 101]));
        $this->refused(fn () => $this->call('courses_list', ['page' => 0]));
        $this->refused(fn () => $this->call('courses_list', ['search' => str_repeat('a', 201)]));
    }

    public function test_shared_step_hierarchy_uses_course_context_not_post_parent_and_rejects_incomplete_large_results(): void
    {
        $this->boot();
        $first = $this->call('course_get', ['course_id' => 10]);
        $second = $this->call('course_get', ['course_id' => 11]);
        self::assertSame(100, $first['hierarchy']['lessons'][0]['id']);
        self::assertSame([101], array_column($first['hierarchy']['lessons'][0]['topics'], 'id'));
        self::assertSame(100, $second['hierarchy']['lessons'][0]['id']);
        self::assertSame([], $second['hierarchy']['lessons'][0]['topics']);
        self::assertSame([100, 101, 102], array_column($first['hierarchy']['steps'], 'id'));
        $GLOBALS['ld']['steps'][10] = range(100, 300);
        $this->refused(fn () => $this->call('course_get', ['course_id' => 10]), '200');
        $GLOBALS['ld']['steps'][10] = [100, 102];
        $this->refused(fn () => $this->call('course_get', ['course_id' => 10]), 'אינו תואם');
    }

    public function test_missing_hierarchy_api_is_explicitly_unavailable_instead_of_guessing_parent_relationships(): void
    {
        $this->boot(['learndash_get_topic_list']);
        $course = $this->call('course_get', ['course_id' => 10]);
        self::assertFalse($course['hierarchy_available']);
        self::assertNull($course['hierarchy']);
        self::assertStringContainsString('post_parent', $course['reason']);
    }

    public function test_group_member_pages_use_bounded_queries_and_hide_privileged_users_and_email_labels(): void
    {
        $this->boot();
        foreach ([7, 8, 9] as $user) {
            $GLOBALS['ld']['meta'][$user]['learndash_group_users_20'] = ['20'];
        }
        $GLOBALS['ld']['users'][7]->display_name = 'Name private@example.test';
        $first = $this->call('group_get', ['group_id' => 20, 'limit' => 1]);
        self::assertSame([7], array_column($first['members'], 'id'));
        self::assertTrue($first['has_more']);
        self::assertSame('תלמיד #7', $first['members'][0]['display_name']);
        self::assertStringNotContainsString('@', json_encode($first));
        self::assertSame(2, $GLOBALS['ld']['user_queries'][0]['number']);
        $second = $this->call('group_get', ['group_id' => 20, 'page' => 2, 'limit' => 1]);
        self::assertSame([8], array_column($second['members'], 'id'));
        $third = $this->call('group_get', ['group_id' => 20, 'page' => 3, 'limit' => 1]);
        self::assertSame([], $third['members']);
        self::assertFalse($third['has_more']);
        self::assertSame(0, $GLOBALS['ld']['unbounded_group_reads'] ?? 0);
    }

    public function test_membership_context_limits_refuse_excessive_combined_group_course_relationships(): void
    {
        $this->boot();
        $GLOBALS['ld']['group_override'][7] = range(20, 120);
        foreach (range(20, 120) as $group) {
            $GLOBALS['ld']['group_courses'][$group] = [10, 11];
            $GLOBALS['ld']['meta'][7]['learndash_group_users_'.$group] = [(string) $group];
        }
        $this->refused(fn () => $this->read(), '200');
        self::assertSame([], $GLOBALS['ld']['native_calls']);
    }

    public function test_fresh_raw_group_membership_invalidates_approval_even_when_native_inverse_cache_is_stale(): void
    {
        $this->boot();
        $GLOBALS['ld']['meta'][7]['course_10_access_from'] = ['1600000000'];
        $prepared = $this->prepare('remove');
        $GLOBALS['ld']['posts'][21] = (object) ['ID' => 21, 'post_type' => 'groups', 'post_title' => 'New inherited access', 'post_status' => 'publish'];
        $GLOBALS['ld']['group_courses'][21] = [10];
        $GLOBALS['ld']['meta'][7]['learndash_group_users_21'] = ['21'];
        $GLOBALS['ld']['group_override'][7] = [];
        $this->refused(fn () => $this->apply($prepared), 'השתנו');
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        self::assertSame(['1600000000'], $this->metadata(7, 'course_10_access_from'));
        self::assertSame(['21'], $this->metadata(7, 'learndash_group_users_21'));
        $fresh = $this->read();
        self::assertFalse($fresh['writable']);
        self::assertContains('group:21', $fresh['state']['access_sources']);
        self::assertNotEmpty($GLOBALS['ld']['group_meta_queries']);
    }

    public function test_malformed_duplicate_unavailable_or_excessive_raw_group_rows_are_readonly(): void
    {
        $this->boot();
        $cases = [
            [['meta_key' => 'learndash_group_users_020', 'meta_value' => '20']],
            [['meta_key' => 'learndash_group_users_20', 'meta_value' => '21']],
            [['meta_key' => 'learndash_group_users_20', 'meta_value' => ['20']]],
            [['meta_key' => 'learndash_group_users_20', 'meta_value' => '20'], ['meta_key' => 'learndash_group_users_20', 'meta_value' => '20']],
            false,
            array_map(static fn (int $id): array => ['meta_key' => 'learndash_group_users_'.$id, 'meta_value' => (string) $id], range(20, 220)),
        ];
        foreach ($cases as $rows) {
            ld_fixture_reset();
            $GLOBALS['ld']['group_meta_result'] = $rows;
            self::assertFalse($this->read()['writable']);
            $this->refused(fn () => $this->prepare());
            self::assertSame([], $GLOBALS['ld']['native_calls']);
        }
    }

    public function test_progress_reports_are_readonly_and_zero_steps_do_not_claim_completion(): void
    {
        $this->boot();
        $first = $this->call('student_course_get', ['user_id' => 7, 'course_id' => 10]);
        self::assertSame(['total' => 3, 'completed' => 1, 'percentage' => 33], $first['progress']);
        $empty = $this->call('student_course_get', ['user_id' => 7, 'course_id' => 11]);
        self::assertSame(['total' => 0, 'completed' => 0, 'percentage' => 0], $empty['progress']);
        $GLOBALS['ld']['progress']['7:10'] = ['unexpected' => 'result'];
        self::assertNull($this->call('student_course_get', ['user_id' => 7, 'course_id' => 10])['progress']);
        self::assertSame([], $GLOBALS['ld']['native_calls']);
        self::assertSame([], $GLOBALS['ld']['options']);
    }
}
