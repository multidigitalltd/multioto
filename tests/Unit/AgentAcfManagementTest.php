<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentAcfManagementTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../Support/wordpress-acf-management-stubs.php';
        foreach (['acf_fields', 'acf_values', 'acf_refs', 'acf_meta', 'acf_data', 'acf_posts', 'acf_users', 'acf_terms', 'acf_writes', 'acf_read_formats', 'acf_object_terms'] as $key) {
            $GLOBALS[$key] = [];
        }
        for ($i = 1; $i <= 10; $i++) {
            $GLOBALS['acf_posts'][$i] = ['ID' => $i, 'post_type' => $i >= 5 ? 'attachment' : 'post', 'post_status' => 'publish', 'post_mime_type' => 'image/jpeg'];
        }
        $GLOBALS['acf_users'][2] = ['roles' => ['subscriber']];
        $GLOBALS['acf_terms'][3] = ['taxonomy' => 'category'];
    }

    private function field(string $type, $value, array $settings = [], string $key = 'field_root'): array
    {
        $field = ['key' => $key, 'name' => substr($key, 6), 'label' => 'Field', 'type' => $type] + $settings;
        $GLOBALS['acf_fields'][$key] = $field;
        $GLOBALS['acf_values'][1][$key] = $value;
        $GLOBALS['acf_meta'][1][$field['name']] = is_array($value) ? count($value) : $value;
        $GLOBALS['acf_refs'][1][$field['name']] = $key;

        return $field;
    }

    private function snapshot(string $key = 'field_root'): array
    {
        return \Multioto_Agent_Acf_Management::state(['context' => 'post', 'id' => 1, 'acf_id' => 1], $GLOBALS['acf_fields'][$key]);
    }

    private function prepare(array $operations, ?array $expected = null, string $key = 'field_root'): array
    {
        return \Multioto_Agent_Acf_Management::call('wp_acf_prepare', ['context' => 'post', 'id' => 1, 'field_key' => $key, 'expected' => $expected ?? $this->snapshot($key), 'operations' => $operations]);
    }

    private function apply(array $proposal, string $key = 'field_root'): array
    {
        return \Multioto_Agent_Acf_Management::call('wp_acf_update', ['context' => 'post', 'id' => 1, 'field_key' => $key, 'expected' => $proposal['expected'], 'prepared' => $proposal['prepared']]);
    }

    private function undo(array $result, string $key = 'field_root'): array
    {
        return \Multioto_Agent_Acf_Management::call('wp_acf_update', ['context' => 'post', 'id' => 1, 'field_key' => $key, 'expected' => $result['after'], 'restore' => $result['before']]);
    }

    private function refused(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected refusal');
        } catch (\Multioto_Agent_Rpc_Error $e) {
            self::assertSame(-32602, $e->getCode());
        }
    }

    public function test_typed_native_scalar_values_round_trip_through_sealed_proposals(): void
    {
        foreach ([
            ['text', 'Old', 'New', []], ['textarea', 'Old', "New\nLine", []], ['email', 'old@example.test', 'new@example.test', []], ['url', 'https://old.test', 'https://new.test', []],
            ['password', 'super-secret', 'new-secret', []], ['wysiwyg', '<p>Old</p>', '<p><em>New</em></p>', []], ['oembed', 'https://old.test', 'https://new.test', []],
            ['number', '1', '2.5', ['min' => 0, 'max' => 3, 'step' => 0.5]], ['range', '1', '2', ['min' => 0, 'max' => 5, 'step' => 1]], ['true_false', 0, 1, []],
            ['select', 'a', 'b', ['choices' => ['a' => 'A', 'b' => 'B']]], ['radio', 'a', 'b', ['choices' => ['a' => 'A', 'b' => 'B']]], ['button_group', 'a', 'b', ['choices' => ['a' => 'A', 'b' => 'B']]],
            ['checkbox', ['a'], ['b'], ['choices' => ['a' => 'A', 'b' => 'B']]], ['date_picker', '20261001', '20261008', []], ['date_time_picker', '2026-10-01 12:00:00', '2026-10-08 13:30:00', []],
            ['time_picker', '12:00:00', '13:30:00', []], ['color_picker', '#fff', '#aabbcc', []], ['link', ['url' => 'https://old.test', 'title' => 'old', 'target' => ''], ['url' => 'https://new.test', 'title' => 'new', 'target' => '_blank'], []],
            ['google_map', ['address' => 'Old', 'lat' => 0.0, 'lng' => 0.0], ['address' => 'New', 'lat' => 32.1, 'lng' => 34.8], []],
        ] as [$type,$old,$new,$settings]) {
            $this->field($type, $old, $settings);
            $proposal = $this->prepare([['op' => 'set', 'path' => [], 'value' => $new]]);
            self::assertSame($old, $GLOBALS['acf_values'][1]['field_root'], $type.' preparation wrote');
            $result = $this->apply($proposal);
            self::assertSame($new, $GLOBALS['acf_values'][1]['field_root'], $type);
            $this->undo($result);
            self::assertSame($old, $GLOBALS['acf_values'][1]['field_root'], $type.' undo');
        }
        self::assertNotContains(true, $GLOBALS['acf_read_formats']);
    }

    public function test_native_reference_fields_validate_existing_objects_and_restore_exact_values(): void
    {
        foreach ([['image', 5, 6, []], ['file', 5, 6, []], ['gallery', [5], [6], []], ['relationship', [1], [2], []], ['post_object', 1, 2, []], ['page_link', 1, 2, []], ['user', false, 2, []], ['taxonomy', [], [3], ['taxonomy' => 'category', 'field_type' => 'checkbox']]] as [$type,$old,$new,$settings]) {
            $this->field($type, $old, $settings);
            $proposal = $this->prepare([['op' => 'set', 'path' => [], 'value' => $new]]);
            $result = $this->apply($proposal);
            self::assertSame($new, $GLOBALS['acf_values'][1]['field_root'], $type);
            $this->undo($result);
            self::assertSame($old, $GLOBALS['acf_values'][1]['field_root'], $type);
        }
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => [9999]]]));
    }

    public function test_repeater_nested_patch_preserves_unseen_siblings_and_reorders_rows_reversibly(): void
    {
        $children = [['key' => 'field_title', 'name' => 'title', 'type' => 'text', 'required' => 1], ['key' => 'field_private', 'name' => 'private', 'type' => 'password']];
        $old = [['field_title' => 'First', 'field_private' => 'secret-a'], ['field_title' => 'Second', 'field_private' => 'secret-b']];
        $this->field('repeater', $old, ['sub_fields' => $children, 'min' => 1, 'max' => 3]);
        $proposal = $this->prepare([['op' => 'set', 'path' => [1, 'field_title'], 'value' => 'Changed'], ['op' => 'move', 'path' => [], 'index' => 1, 'to' => 0]]);
        self::assertStringNotContainsString('secret-a', json_encode($proposal));
        self::assertStringNotContainsString('secret-b', json_encode($proposal));
        $result = $this->apply($proposal);
        self::assertSame([['field_title' => 'Changed', 'field_private' => 'secret-b'], $old[0]], $GLOBALS['acf_values'][1]['field_root']);
        $this->undo($result);
        self::assertSame($old, $GLOBALS['acf_values'][1]['field_root']);
    }

    public function test_group_clone_and_flexible_content_paths_and_rows_are_typed(): void
    {
        $child = ['key' => 'field_title', 'name' => 'prefixed_title', 'type' => 'text', 'required' => 1];
        foreach (['group', 'clone'] as $type) {
            $this->field($type, ['field_title' => 'Old'], ['sub_fields' => [$child]]);
            $result = $this->apply($this->prepare([['op' => 'set', 'path' => ['field_title'], 'value' => 'New']]));
            self::assertSame(['field_title' => 'New'], $GLOBALS['acf_values'][1]['field_root']);
            $this->undo($result);
        }
        $old = [['acf_fc_layout' => 'hero', 'field_title' => 'Old']];
        $this->field('flexible_content', $old, ['layouts' => [['name' => 'hero', 'min' => 1, 'max' => 2, 'sub_fields' => [$child]]]]);
        $result = $this->apply($this->prepare([['op' => 'insert', 'path' => [], 'index' => 1, 'value' => ['acf_fc_layout' => 'hero', 'field_title' => 'New']]]));
        self::assertCount(2, $GLOBALS['acf_values'][1]['field_root']);
        $this->undo($result);
        self::assertSame($old, $GLOBALS['acf_values'][1]['field_root']);
        $this->refused(fn () => $this->prepare([['op' => 'insert', 'path' => [], 'index' => 1, 'value' => ['acf_fc_layout' => 'invented', 'field_title' => 'Bad']]]));
        $this->refused(fn () => $this->prepare([['op' => 'remove', 'path' => [], 'index' => 0]]));
    }

    public function test_invalid_batch_changes_nothing_and_enforces_choices_required_and_numeric_limits(): void
    {
        $this->field('number', '1', ['min' => 0, 'max' => 10, 'step' => 2]);
        foreach ([11, 3, 'NaN', [1]] as $bad) {
            $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => $bad]]));
        }
        $this->field('text', 'old', ['required' => 1, 'maxlength' => 4]);
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => 'good'], ['op' => 'clear', 'path' => []]]));
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => 'too long']]));
        $this->field('select', 'a', ['choices' => ['a' => 'A']]);
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => 'bad']]));
        self::assertSame([], $GLOBALS['acf_writes']);
    }

    public function test_snapshot_tampering_schema_drift_target_replay_and_stale_state_are_refused(): void
    {
        $this->field('text', 'old');
        $snapshot = $this->snapshot();
        $proposal = $this->prepare([['op' => 'set', 'path' => [], 'value' => 'new']]);
        $bad = $snapshot;
        $bad['token'][50] = $bad['token'][50] === 'a' ? 'b' : 'a';
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => 'new']], $bad));
        $GLOBALS['acf_fields']['field_root']['required'] = 1;
        $this->refused(fn () => $this->apply($proposal));
        unset($GLOBALS['acf_fields']['field_root']['required']);
        $this->refused(fn () => \Multioto_Agent_Acf_Management::call('wp_acf_update', ['context' => 'post', 'id' => 2, 'field_key' => 'field_root', 'expected' => $proposal['expected'], 'prepared' => $proposal['prepared']]));
        $GLOBALS['acf_values'][1]['field_root'] = 'changed elsewhere';
        $this->refused(fn () => $this->apply($proposal));
        self::assertSame([], $GLOBALS['acf_writes']);
    }

    public function test_missing_field_restores_absence_instead_of_empty_value_and_reference(): void
    {
        $this->field('text', false);
        unset($GLOBALS['acf_values'][1]['field_root'],$GLOBALS['acf_meta'][1]['root'],$GLOBALS['acf_refs'][1]['root']);
        $result = $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => 'created']]));
        $this->undo($result);
        self::assertArrayNotHasKey('field_root', $GLOBALS['acf_values'][1]);
        self::assertArrayNotHasKey('root', $GLOBALS['acf_meta'][1]);
        self::assertArrayNotHasKey('root', $GLOBALS['acf_refs'][1]);
    }

    public function test_stale_undo_keeps_dashboard_edits_and_failed_write_compensates(): void
    {
        $this->field('text', 'old');
        $result = $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => 'new']]));
        $GLOBALS['acf_values'][1]['field_root'] = 'dashboard';
        $this->refused(fn () => $this->undo($result));
        self::assertSame('dashboard', $GLOBALS['acf_values'][1]['field_root']);
        $GLOBALS['acf_fail_once'] = true;
        $this->refused(fn () => $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => 'failing']])));
        self::assertSame('dashboard', $GLOBALS['acf_values'][1]['field_root']);
    }

    public function test_bidirectional_relationship_undo_restores_removed_and_added_inverse_records(): void
    {
        $this->field('relationship', [2], ['bidirectional' => 1, 'bidirectional_target' => ['field_inverse']]);
        $this->field('relationship', [], [], 'field_inverse');
        $GLOBALS['acf_values'][2]['field_inverse'] = [1, 4];
        $GLOBALS['acf_meta'][2]['inverse'] = 2;
        $GLOBALS['acf_refs'][2]['inverse'] = 'field_inverse';
        $GLOBALS['acf_values'][3]['field_inverse'] = [4];
        $GLOBALS['acf_meta'][3]['inverse'] = 1;
        $GLOBALS['acf_refs'][3]['inverse'] = 'field_inverse';
        $result = $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => [3]]]));
        self::assertSame([4], $GLOBALS['acf_values'][2]['field_inverse']);
        self::assertSame([4, 1], $GLOBALS['acf_values'][3]['field_inverse']);
        $this->undo($result);
        self::assertSame([1, 4], $GLOBALS['acf_values'][2]['field_inverse']);
        self::assertSame([4], $GLOBALS['acf_values'][3]['field_inverse']);
    }

    public function test_inverse_changes_between_prepare_and_apply_are_not_overwritten(): void
    {
        $this->field('relationship', [], ['bidirectional' => 1, 'bidirectional_target' => ['field_inverse']]);
        $this->field('relationship', [], [], 'field_inverse');
        $proposal = $this->prepare([['op' => 'set', 'path' => [], 'value' => [2]]]);
        $GLOBALS['acf_values'][2]['field_inverse'] = [4];
        $GLOBALS['acf_meta'][2]['inverse'] = 1;
        $this->refused(fn () => $this->apply($proposal));
        self::assertSame([], $GLOBALS['acf_writes']);
    }

    public function test_taxonomy_save_terms_restores_independently_existing_assignments(): void
    {
        $this->field('taxonomy', [], ['taxonomy' => 'category', 'field_type' => 'checkbox', 'save_terms' => 1]);
        $GLOBALS['acf_object_terms'][1]['category'] = [9];
        $result = $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => [3]]]));
        self::assertSame([3], $GLOBALS['acf_object_terms'][1]['category']);
        $this->undo($result);
        self::assertSame([9], $GLOBALS['acf_object_terms'][1]['category']);
    }

    public function test_opaque_and_protected_nested_fields_cannot_be_overwritten(): void
    {
        $this->field('group', ['field_name' => 'old', 'field_secret' => 'secret'], ['sub_fields' => [['key' => 'field_name', 'name' => 'name', 'type' => 'text'], ['key' => 'field_secret', 'name' => 'api_key', 'type' => 'text']]]);
        $result = $this->apply($this->prepare([['op' => 'set', 'path' => ['field_name'], 'value' => 'new']]));
        self::assertSame('secret', $GLOBALS['acf_values'][1]['field_root']['field_secret']);
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => ['field_secret'], 'value' => 'replace']]));
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => ['field_name' => 'erase sibling']]]));
    }

    public function test_icon_picker_native_sources_round_trip_and_enforce_enabled_tabs(): void
    {
        foreach ([['type' => 'dashicons', 'value' => 'dashicons-admin-home'], ['type' => 'url', 'value' => 'https://example.test/icon.svg'], ['type' => 'media_library', 'value' => 5]] as $icon) {
            $this->field('icon_picker', '');
            $result = $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => $icon]]));
            self::assertSame($icon, $GLOBALS['acf_values'][1]['field_root']);
            $this->undo($result);
            self::assertSame('', $GLOBALS['acf_values'][1]['field_root']);
        }
        $this->field('icon_picker', '', ['tabs' => ['dashicons']]);
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => ['type' => 'url', 'value' => 'https://example.test/icon.svg']]]));
    }

    public function test_writing_meter_excludes_existing_reordered_rows_and_password_values(): void
    {
        $this->field('repeater', [['field_text' => 'First', 'field_pass' => 'private-one'], ['field_text' => 'Second', 'field_pass' => 'private-two']], ['sub_fields' => [['key' => 'field_text', 'name' => 'text', 'type' => 'text'], ['key' => 'field_pass', 'name' => 'password', 'type' => 'password']]]);
        $proposal = $this->prepare([['op' => 'move', 'path' => [], 'index' => 1, 'to' => 0]]);
        self::assertSame('', $proposal['writing_text']);
        self::assertNotEmpty($proposal['notes']);
        $proposal = $this->prepare([['op' => 'set', 'path' => [0, 'field_text'], 'value' => 'New copy'], ['op' => 'set', 'path' => [0, 'field_pass'], 'value' => 'new-secret']]);
        self::assertSame('New copy', $proposal['writing_text']);
        self::assertStringNotContainsString('new-secret', json_encode($proposal));
    }

    public function test_protected_descendants_cannot_be_deleted_but_intact_rows_can_move(): void
    {
        $old = [['field_title' => 'One', 'field_secret' => 'secret-one'], ['field_title' => 'Two', 'field_secret' => 'secret-two']];
        $this->field('repeater', $old, ['sub_fields' => [['key' => 'field_title', 'name' => 'title', 'type' => 'text'], ['key' => 'field_secret', 'name' => 'api_key', 'type' => 'text']]]);
        $this->refused(fn () => $this->prepare([['op' => 'remove', 'path' => [], 'index' => 0]]));
        $this->refused(fn () => $this->prepare([['op' => 'clear', 'path' => []]]));
        $result = $this->apply($this->prepare([['op' => 'move', 'path' => [], 'index' => 1, 'to' => 0]]));
        self::assertSame(array_reverse($old), $GLOBALS['acf_values'][1]['field_root']);
        $this->undo($result);
        self::assertSame($old, $GLOBALS['acf_values'][1]['field_root']);
    }

    public function test_nested_metadata_changes_and_inverse_schema_changes_invalidate_approval(): void
    {
        $this->field('group', ['field_text' => 'Old'], ['sub_fields' => [['key' => 'field_text', 'name' => 'text', 'type' => 'text']]]);
        $proposal = $this->prepare([['op' => 'set', 'path' => ['field_text'], 'value' => 'New']]);
        $GLOBALS['acf_refs'][1]['root_text'] = 'field_other';
        $this->refused(fn () => $this->apply($proposal));
        $this->field('relationship', [], ['bidirectional' => 1, 'bidirectional_target' => ['field_inverse']]);
        $this->field('relationship', [], [], 'field_inverse');
        $proposal = $this->prepare([['op' => 'set', 'path' => [], 'value' => [2]]]);
        $GLOBALS['acf_fields']['field_inverse']['required'] = 1;
        $this->refused(fn () => $this->apply($proposal));
        self::assertSame([], $GLOBALS['acf_writes']);
    }

    public function test_custom_choice_values_are_allowed_only_without_shared_schema_mutations(): void
    {
        $this->field('checkbox', ['registered'], ['choices' => ['registered' => 'Registered'], 'allow_custom' => 1]);
        $result = $this->apply($this->prepare([['op' => 'set', 'path' => [], 'value' => ['custom']]]));
        self::assertSame(['custom'], $GLOBALS['acf_values'][1]['field_root']);
        $this->undo($result);
        $this->field('checkbox', ['unregistered'], ['choices' => ['registered' => 'Registered'], 'allow_custom' => 1, 'save_custom' => 1]);
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => [], 'value' => ['registered']]]));
        $this->field('group', ['field_choices' => ['unregistered'], 'field_text' => 'Old'], ['sub_fields' => [['key' => 'field_choices', 'name' => 'choices', 'type' => 'checkbox', 'choices' => ['registered' => 'Registered'], 'allow_custom' => 1, 'save_custom' => 1], ['key' => 'field_text', 'name' => 'text', 'type' => 'text']]]);
        $this->refused(fn () => $this->prepare([['op' => 'set', 'path' => ['field_text'], 'value' => 'New']]));
    }
}
