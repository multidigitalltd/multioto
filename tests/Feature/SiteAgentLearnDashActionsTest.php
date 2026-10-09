<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionApplier;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentPermissions;
use App\Services\SiteAgent\SiteAgentToolbox;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** LearnDash membership writes need a live scoped read and opaque native snapshots. */
class SiteAgentLearnDashActionsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private array $answers = [];

    private array $calls = [];

    private array $selector = ['user_id' => 8, 'kind' => 'course', 'target_id' => 74];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        $this->site = Site::factory()->create([
            'domain' => 'learndash.example', 'mcp_enabled' => true, 'mcp_secret' => 'site-secret',
            'mcp_endpoint' => 'https://learndash.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['server' => ['version' => '1.11.0'], 'tools' => array_map(fn (string $name): array => ['name' => $name], [
                'ld_capabilities', 'ld_courses_list', 'ld_course_get', 'ld_student_course_get', 'ld_groups_list', 'ld_group_get',
                'ld_membership_get', 'ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert',
            ])],
        ]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->assertSame($this->site->id, $site->id);
            $this->calls[] = [$tool, $args];
            if (! array_key_exists($tool, $this->answers)) {
                throw new RuntimeException('Unexpected tool: '.$tool);
            }
            $answer = $this->answers[$tool];

            return $answer instanceof Closure ? $answer($args) : $answer;
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $answer): string => $answer['_wire_text'] ?? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $this->answers['ld_membership_get'] = $this->record();
        $this->answers['ld_membership_prepare'] = $this->prepared();
        $this->answers['ld_membership_apply'] = ['changed' => true, 'selector' => $this->selector, 'expected' => $this->seal('before'),
            'before' => $this->seal('before'), 'after' => $this->seal('after')];
        $this->answers['ld_membership_revert'] = ['changed' => true, 'selector' => $this->selector, 'expected' => $this->seal('after'),
            'before' => $this->seal('after'), 'after' => $this->seal('before')];
    }

    public function test_membership_reads_strip_snapshots_and_nested_email_addresses_and_grant_only_scoped_references(): void
    {
        $this->answers['ld_membership_get']['user']['email'] = 'private.student@example.test';
        $this->answers['ld_membership_get']['user']['profile'] = ['user_email' => 'nested.secret@example.test'];
        $this->answers['ld_membership_get']['restore'] = 'PRIVATE-RESTORATION-STATE';
        $this->answers['ld_membership_get']['internal'] = ['backup' => 'PRIVATE-NESTED-STATE'];
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_ld_membership', $this->selector + ['expected' => $this->seal('forged')]);
        $this->assertFalse($read['is_error'], $read['content']);
        $this->assertSame(['ld-membership:course:74:8'], $read['ids']);
        $this->assertSame(['ld_membership_get', $this->selector], $this->calls[0]);
        foreach ([$this->seal('before')['token'], 'private.student@example.test', 'nested.secret@example.test', 'snapshot', 'PRIVATE-RESTORATION-STATE', 'PRIVATE-NESTED-STATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $read['content']);
        }
        $this->assertStringContainsString('תלמיד לדוגמה', $read['content']);
        foreach ([[], [8, 74], ['ld-course:74'], ['ld-membership:group:74:8'], ['ld-membership:course:75:8'], ['ld-membership:course:74:9']] as $seen) {
            $this->assertArrayHasKey('error', $this->propose([], $seen));
        }
        $this->assertSame(['ld_membership_get'], array_column($this->calls, 0));
    }

    public function test_preparation_rereads_live_state_and_ignores_model_supplied_snapshot_tokens(): void
    {
        $this->answers['ld_membership_get']['snapshot'] = $this->seal('fresh');
        $this->answers['ld_membership_prepare']['expected'] = $this->seal('fresh');
        $offer = $this->propose(['expected' => $this->seal('forged'), 'prepared' => $this->seal('forged')]);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame('learndash_membership', $offer['plan']['operation']);
        $this->assertSame($this->selector, $offer['plan']['arguments']);
        $this->assertSame($this->seal('fresh'), $offer['plan']['expected']);
        $this->assertSame($this->seal('prepared'), $offer['plan']['prepared']);
        $this->assertStringContainsString('תלמיד לדוגמה', $offer['preview']);
        $this->assertStringContainsString('קורס צילום', $offer['preview']);
        $this->assertSame(['ld_membership_get', 'ld_membership_prepare'], array_column($this->calls, 0));
        $this->assertSame($this->seal('fresh'), $this->calls[1][1]['expected']);
        $this->assertSame('add', $this->calls[1][1]['action']);
        $this->assertStringNotContainsString($this->seal('prepared')['token'], $offer['preview']);
    }

    public function test_catalogue_and_group_reads_do_not_authorize_membership_or_generic_wordpress_edits(): void
    {
        $this->answers['ld_courses_list'] = ['courses' => [['id' => 74, 'title' => 'קורס צילום']], 'has_more' => false];
        $courses = app(SiteAgentToolbox::class)->read($this->site, 'find_ld_courses', []);
        $this->assertSame(['ld-course:74'], $courses['ids']);
        $this->answers['ld_group_get'] = ['group' => ['id' => 19, 'title' => 'קבוצת מתחילים'],
            'members' => [['id' => 8, 'display_name' => 'תלמיד לדוגמה', 'email' => 'private.student@example.test']]];
        $group = app(SiteAgentToolbox::class)->read($this->site, 'get_ld_group', ['group_id' => 19]);
        $this->assertSame(['ld-group:19'], $group['ids']);
        $this->assertStringNotContainsString('private.student@example.test', $group['content']);
        $this->calls = [];
        $this->assertArrayHasKey('error', $this->propose([], [...$courses['ids'], ...$group['ids']]));
        $this->assertSame([], $this->calls);
    }

    public function test_malformed_membership_reads_do_not_expose_native_tokens_or_authorize_a_proposal(): void
    {
        $this->answers['ld_membership_get'] = ['_wire_text' => '{"snapshot":{"token":"BROKEN-SECRET"}'];
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_ld_membership', $this->selector);
        $this->assertTrue($read['is_error']);
        $this->assertSame([], $read['ids']);
        $this->assertStringNotContainsString('BROKEN-SECRET', $read['content']);
    }

    public function test_read_responses_for_a_different_course_or_group_are_refused_without_references(): void
    {
        $this->answers['ld_course_get'] = ['course' => ['id' => 75, 'title' => 'הקורס הלא נכון']];
        $this->answers['ld_group_get'] = ['group' => ['id' => 20, 'title' => 'הקבוצה הלא נכונה']];
        $this->answers['ld_student_course_get'] = ['user' => ['id' => 9, 'display_name' => 'תלמיד אחר'], 'course' => ['id' => 74, 'title' => 'קורס צילום']];
        foreach ([['get_ld_course', ['course_id' => 74]], ['get_ld_group', ['group_id' => 19]], ['get_ld_student_course', ['user_id' => 8, 'course_id' => 74]]] as [$name, $input]) {
            $read = app(SiteAgentToolbox::class)->read($this->site, $name, $input);
            $this->assertTrue($read['is_error']);
            $this->assertSame([], $read['ids']);
        }
    }

    public function test_direct_course_removal_discloses_access_that_remains_through_a_group(): void
    {
        $before = $this->state(true, true, ['direct', 'group:19']);
        $after = $this->state(false, true, ['group:19']);
        $this->answers['ld_membership_get']['state'] = $before;
        $this->answers['ld_membership_prepare']['before'] = $before;
        $this->answers['ld_membership_prepare']['after'] = $after;
        $this->answers['ld_membership_prepare']['notes'] = ['הגישה לקורס נשארת דרך הקבוצה, גם אחרי הסרת ההרשמה הישירה.'];
        $offer = $this->propose(['action' => 'remove']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertStringContainsString('הגישה לקורס נשארת דרך הקבוצה', $offer['preview']);
        $this->assertStringContainsString('תישאר גישה לקורס ממקור אחר', $offer['preview']);
        $this->assertStringNotContainsString('כל הגישה תבוטל', $offer['preview']);
        $this->assertNotContains('ld_membership_apply', array_column($this->calls, 0));
    }

    public function test_group_membership_proposals_show_which_course_access_will_change(): void
    {
        $this->selector = ['user_id' => 8, 'kind' => 'group', 'target_id' => 19];
        $this->answers['ld_membership_get'] = $this->record();
        $this->answers['ld_membership_get']['target'] = ['id' => 19, 'title' => 'קבוצת מתחילים', 'type' => 'group'];
        $this->answers['ld_membership_get']['state'] = $this->state(false, null, []);
        $this->answers['ld_membership_get']['impacts'] = [['course_id' => 74, 'title' => 'קורס צילום', 'effective_access' => false]];
        $this->answers['ld_membership_prepare'] = $this->prepared();
        $this->answers['ld_membership_prepare']['target'] = $this->answers['ld_membership_get']['target'];
        $this->answers['ld_membership_prepare']['before'] = $this->state(false, null, []);
        $this->answers['ld_membership_prepare']['after'] = $this->state(true, null, ['direct']);
        $this->answers['ld_membership_prepare']['impacts'] = [['course_id' => 74, 'title' => 'קורס צילום', 'before_access' => false, 'after_access' => true]];
        $offer = $this->propose([], ['ld-membership:group:19:8']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertStringContainsString('קבוצת מתחילים', $offer['preview']);
        $this->assertStringContainsString('קורס צילום', $offer['preview']);
        $this->assertSame($this->selector, $offer['plan']['arguments']);
        $this->assertNotContains('ld_membership_apply', array_column($this->calls, 0));
    }

    public function test_uncertain_direct_membership_or_read_only_sites_do_not_prepare_writes(): void
    {
        $this->answers['ld_membership_get']['writable'] = false;
        $this->answers['ld_membership_get']['reason'] = 'גרסת LearnDash אינה מספקת קריאה אמינה של הרשמה ישירה.';
        $this->answers['ld_membership_get']['state']['direct_member'] = null;
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertSame(['ld_membership_get'], array_column($this->calls, 0));
    }

    public function test_group_preparation_cannot_omit_duplicate_or_change_known_course_access_impacts(): void
    {
        $this->selector = ['user_id' => 8, 'kind' => 'group', 'target_id' => 19];
        $read = $this->record();
        $read['target'] = ['id' => 19, 'title' => 'קבוצת מתחילים', 'type' => 'group'];
        $read['state'] = $this->state(false, null, []);
        $read['impacts'] = [['course_id' => 74, 'title' => 'קורס צילום', 'effective_access' => false],
            ['course_id' => 75, 'title' => 'קורס עריכה', 'effective_access' => true]];
        $this->answers['ld_membership_get'] = $read;
        $prepared = $this->prepared();
        $prepared['target'] = $read['target'];
        $prepared['before'] = $read['state'];
        $prepared['after'] = $this->state(true, null, ['direct']);
        $prepared['impacts'] = [['course_id' => 74, 'title' => 'קורס צילום', 'before_access' => false, 'after_access' => true],
            ['course_id' => 75, 'title' => 'קורס עריכה', 'before_access' => true, 'after_access' => true]];
        $omitted = $prepared;
        array_pop($omitted['impacts']);
        $duplicate = $prepared;
        $duplicate['impacts'][1] = $duplicate['impacts'][0];
        $changed = $prepared;
        $changed['impacts'][1]['before_access'] = false;
        foreach ([$omitted, $duplicate, $changed] as $corrupt) {
            $this->answers['ld_membership_prepare'] = $corrupt;
            $this->assertArrayHasKey('error', $this->propose([], ['ld-membership:group:19:8']));
        }
        $this->assertNotContains('ld_membership_apply', array_column($this->calls, 0));
    }

    public function test_selectors_and_actions_are_validated_without_remote_calls(): void
    {
        foreach ([['user_id' => 0], ['user_id' => true], ['target_id' => '74'], ['target_id' => -1], ['kind' => 'lesson'], ['action' => 'complete']] as $bad) {
            $this->calls = [];
            $this->assertArrayHasKey('error', $this->propose($bad), json_encode($bad));
            $this->assertSame([], $this->calls);
        }
    }

    public function test_mismatched_or_unsigned_read_and_preparation_results_never_create_an_offer(): void
    {
        foreach ([['snapshot' => []], ['selector' => ['user_id' => 9, 'kind' => 'course', 'target_id' => 74]]] as $corruption) {
            $this->answers['ld_membership_get'] = array_replace($this->record(), $corruption);
            $this->calls = [];
            $this->assertArrayHasKey('error', $this->propose());
            $this->assertNotContains('ld_membership_prepare', array_column($this->calls, 0));
        }
        $this->answers['ld_membership_get'] = $this->record();
        foreach ([['changed' => false], ['prepared' => []], ['expected' => $this->seal('other')], ['selector' => ['user_id' => 8, 'kind' => 'group', 'target_id' => 74]]] as $corruption) {
            $this->answers['ld_membership_prepare'] = array_replace($this->prepared(), $corruption);
            $this->assertArrayHasKey('error', $this->propose(), json_encode($corruption));
        }
        $this->assertNotContains('ld_membership_apply', array_column($this->calls, 0));
    }

    public function test_apply_and_undo_preserve_the_exact_target_and_use_opposite_native_snapshots(): void
    {
        $request = $this->request($this->propose());
        $applier = app(SiteActionApplier::class);
        $applied = $applier->apply($this->site, $request);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertSame(['ld_membership_apply', $this->selector + ['expected' => $this->seal('before'), 'prepared' => $this->seal('prepared')]], end($this->calls));
        $this->assertTrue($applier->reverts('learndash_membership'));
        $this->assertSame('learndash_membership', $applied['restore']['kind']);
        $this->assertTrue($applier->revert($this->site, $applied['restore'])['ok']);
        $this->assertSame(['ld_membership_revert', $this->selector + ['expected' => $this->seal('after'), 'restore' => $this->seal('before')]], end($this->calls));
    }

    public function test_student_permission_blocks_reads_hidden_calls_proposals_apply_and_undo_without_hiding_catalogue(): void
    {
        $request = $this->request($this->propose());
        $restore = ['kind' => 'learndash_membership', 'arguments' => $this->selector, 'before' => $this->seal('before'), 'after' => $this->seal('after')];
        config(['siteagent.assistant.disabled_permissions' => ['learndash_students']]);
        $this->calls = [];
        $names = array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name');
        $this->assertContains('find_ld_courses', $names);
        foreach (['get_ld_membership', 'get_ld_student_course', 'get_ld_group'] as $name) {
            $this->assertNotContains($name, $names);
            $this->assertTrue(app(SiteAgentToolbox::class)->read($this->site, $name, $this->selector)['is_error']);
        }
        $this->assertFalse(app(SiteAgentPermissions::class)->allowsTool('propose_ld_membership'));
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        $this->assertFalse(app(SiteActionApplier::class)->revert($this->site, $restore)['ok']);
        $this->assertSame([], $this->calls);
    }

    public function test_catalogue_permission_blocks_its_direct_read_calls_separately_from_student_reads(): void
    {
        config(['siteagent.assistant.disabled_permissions' => ['learndash_catalog']]);
        $names = array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name');
        $this->assertContains('get_ld_membership', $names);
        foreach (['get_ld_capabilities', 'find_ld_courses', 'get_ld_course', 'find_ld_groups'] as $name) {
            $this->assertNotContains($name, $names);
            $this->assertTrue(app(SiteAgentToolbox::class)->read($this->site, $name, [])['is_error']);
        }
        $this->assertSame([], $this->calls);
    }

    public function test_old_sites_and_missing_current_write_capabilities_refuse_execution(): void
    {
        $request = $this->request($this->propose());
        $this->site->mcp_capabilities = ['tools' => [['name' => 'ld_courses_list'], ['name' => 'ld_membership_get']]];
        $this->calls = [];
        $this->assertNotContains('propose_ld_membership', array_column(app(SiteActionProposer::class)->definitions($this->site), 'name'));
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        $this->assertSame([], $this->calls);
    }

    public function test_older_or_unknown_capability_scans_never_advertise_or_dispatch_learndash_tools(): void
    {
        $capabilities = $this->site->mcp_capabilities;
        foreach ([
            ['server' => ['version' => '1.10.9'], 'tools' => $capabilities['tools']],
            ['tools' => $capabilities['tools']],
            ['server' => ['version' => '1.11.0'], 'tools' => []],
        ] as $scan) {
            $this->site->mcp_capabilities = $scan;
            $this->assertNotContains('get_ld_membership', array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name'));
            $this->assertNotContains('propose_ld_membership', array_column(app(SiteActionProposer::class)->definitions($this->site), 'name'));
            $this->assertTrue(app(SiteAgentToolbox::class)->read($this->site, 'get_ld_membership', $this->selector)['is_error']);
            $this->assertArrayHasKey('error', $this->propose());
        }
        $this->assertSame([], $this->calls);
    }

    public function test_failed_or_incomplete_mutations_are_not_reported_as_success(): void
    {
        $request = $this->request($this->propose());
        $this->answers['ld_membership_apply'] = function (): array {
            throw new RuntimeException('LearnDash membership changed since approval');
        };
        $failed = app(SiteActionApplier::class)->apply($this->site, $request);
        $this->assertFalse($failed['ok']);
        $this->assertNull($failed['restore']);
        foreach ([['changed' => false], ['changed' => true], ['changed' => true, 'before' => $this->seal('before'), 'after' => ['token' => 'invalid']]] as $result) {
            $this->answers['ld_membership_apply'] = $result;
            $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        }
    }

    public function test_remote_error_details_cannot_leak_student_emails_receipts_or_raw_metadata(): void
    {
        $private = 'private.student@example.test PRIVATE-RECEIPT-123 PRIVATE-RAW-USER-META';
        $failure = function () use ($private): array {
            throw new RuntimeException($private);
        };
        $this->answers['ld_membership_get'] = $failure;
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_ld_membership', $this->selector);
        $this->assertTrue($read['is_error']);
        $this->assertSame([], $read['ids']);
        $this->assertStringNotContainsString($private, json_encode($read));
        $proposal = $this->propose();
        $this->assertArrayHasKey('error', $proposal);
        $this->assertStringNotContainsString($private, json_encode($proposal));

        $this->answers['ld_membership_get'] = $this->record();
        $request = $this->request($this->propose());
        $this->answers['ld_membership_apply'] = $failure;
        $applied = app(SiteActionApplier::class)->apply($this->site, $request);
        $this->assertFalse($applied['ok']);
        $this->assertStringNotContainsString($private, json_encode($applied));
        $this->answers['ld_membership_revert'] = $failure;
        $reverted = app(SiteActionApplier::class)->revert($this->site, [
            'kind' => 'learndash_membership', 'arguments' => $this->selector, 'before' => $this->seal('before'), 'after' => $this->seal('after'),
        ]);
        $this->assertFalse($reverted['ok']);
        $this->assertStringNotContainsString($private, json_encode($reverted));
        foreach ([$read, $proposal, $applied, $reverted] as $result) {
            foreach (explode(' ', $private) as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($result));
            }
        }
    }

    public function test_success_receipts_must_match_the_requested_selector_and_expected_snapshot(): void
    {
        $request = $this->request($this->propose());
        $apply = $this->answers['ld_membership_apply'];
        foreach ([['selector' => ['user_id' => 9, 'kind' => 'course', 'target_id' => 74]], ['expected' => $this->seal('unrelated')], ['selector' => null], ['expected' => null]] as $corrupt) {
            $this->answers['ld_membership_apply'] = array_replace($apply, $corrupt);
            $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        }
        $undo = $this->answers['ld_membership_revert'];
        $restore = ['kind' => 'learndash_membership', 'arguments' => $this->selector, 'before' => $this->seal('before'), 'after' => $this->seal('after')];
        foreach ([['selector' => ['user_id' => 8, 'kind' => 'group', 'target_id' => 74]], ['expected' => $this->seal('unrelated')], ['selector' => null], ['expected' => null]] as $corrupt) {
            $this->answers['ld_membership_revert'] = array_replace($undo, $corrupt);
            $this->assertFalse(app(SiteActionApplier::class)->revert($this->site, $restore)['ok']);
        }
    }

    public function test_progress_writes_are_not_advertised_or_dispatched(): void
    {
        $names = array_column(app(SiteActionProposer::class)->definitions($this->site), 'name');
        $this->assertContains('propose_ld_membership', $names);
        foreach (['propose_ld_progress', 'propose_ld_complete_course', 'propose_ld_quiz_grade'] as $name) {
            $this->assertNotContains($name, $names);
            $this->assertFalse(app(SiteActionProposer::class)->isProposal($name));
        }
        $this->assertSame([], $this->calls);
    }

    private function propose(array $input = [], ?array $seen = null): array
    {
        return app(SiteActionProposer::class)->propose($this->site, 'propose_ld_membership', $input + $this->selector + ['action' => 'add'],
            $seen ?? ['ld-membership:course:74:8']);
    }

    private function request(array $offer): SiteAgentRequest
    {
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));

        return new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview']]);
    }

    private function seal(string $tag): array
    {
        return ['version' => hash('sha256', $tag), 'token' => base64_encode('opaque-learndash-'.$tag)];
    }

    private function state(?bool $direct, ?bool $access, array $sources): array
    {
        return ['direct_member' => $direct, 'effective_access' => $access, 'access_sources' => $sources, 'access_from' => null, 'expires_at' => null];
    }

    private function record(): array
    {
        return ['selector' => $this->selector, 'user' => ['id' => 8, 'display_name' => 'תלמיד לדוגמה'],
            'target' => ['id' => 74, 'title' => 'קורס צילום', 'type' => 'course'],
            'state' => $this->state(false, false, []), 'writable' => true, 'reason' => null,
            'impacts' => [], 'snapshot' => $this->seal('before')];
    }

    private function prepared(): array
    {
        return ['selector' => $this->selector, 'user' => ['id' => 8, 'display_name' => 'תלמיד לדוגמה'],
            'target' => ['id' => 74, 'title' => 'קורס צילום', 'type' => 'course'],
            'before' => $this->state(false, false, []), 'after' => $this->state(true, true, ['direct']),
            'expected' => $this->seal('before'), 'prepared' => $this->seal('prepared'), 'notes' => [], 'impacts' => [], 'changed' => true];
    }
}
