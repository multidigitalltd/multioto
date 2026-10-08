<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentAcfNativeStorageTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../Support/wordpress-acf-native-storage.php';
        $GLOBALS['native_fields'] = [];
        $GLOBALS['native_meta'] = [11 => []];
        $GLOBALS['native_data'] = [];
        $GLOBALS['native_writes'] = 0;
    }

    private function field(string $type, string $name, array $children = []): array
    {
        return ['key' => 'field_'.$name, 'name' => $name, '_name' => $name, 'type' => $type, 'label' => $name, 'sub_fields' => $children];
    }

    private function proposal(array $operations): array
    {
        $read = \Multioto_Agent_Acf_Schema::snapshot(['context' => 'post', 'id' => 11]);

        return \Multioto_Agent_Acf_Management::call('wp_acf_prepare', ['context' => 'post', 'id' => 11, 'field_key' => 'field_root', 'expected' => $read['snapshots']['field_root'], 'operations' => $operations]);
    }

    private function apply(array $proposal): array
    {
        return \Multioto_Agent_Acf_Management::call('wp_acf_update', ['context' => 'post', 'id' => 11, 'field_key' => 'field_root', 'expected' => $proposal['expected'], 'prepared' => $proposal['prepared']]);
    }

    private function undo(array $result): void
    {
        \Multioto_Agent_Acf_Management::call('wp_acf_update', ['context' => 'post', 'id' => 11, 'field_key' => 'field_root', 'expected' => $result['after'], 'restore' => $result['before']]);
    }

    public function test_group_undo_restores_missing_children_and_missing_references_exactly(): void
    {
        $GLOBALS['native_fields'] = [$this->field('group', 'root', [$this->field('text', 'title'), $this->field('text', 'optional')])];
        $GLOBALS['native_meta'][11] = ['root' => '', 'root_title' => 'Old', '_root_title' => 'field_title'];
        $before = $GLOBALS['native_meta'][11];
        $result = $this->apply($this->proposal([['op' => 'set', 'path' => ['field_optional'], 'value' => 'Created']]));
        self::assertSame('Created', $GLOBALS['native_meta'][11]['root_optional']);
        self::assertSame('field_optional', $GLOBALS['native_meta'][11]['_root_optional']);
        $this->undo($result);
        self::assertEquals($before, $GLOBALS['native_meta'][11]);
    }

    public function test_repeater_insert_and_undo_restores_the_full_nested_metadata_footprint(): void
    {
        $GLOBALS['native_fields'] = [$this->field('repeater', 'root', [$this->field('group', 'content', [$this->field('text', 'title'), $this->field('password', 'password')])])];
        \update_field('field_root', [['field_content' => ['field_title' => 'First', 'field_password' => 'hidden-first']]], 11);
        unset($GLOBALS['native_meta'][11]['_root_0_content_title']);
        $before = $GLOBALS['native_meta'][11];
        $proposal = $this->proposal([['op' => 'insert', 'path' => [], 'index' => 1, 'value' => ['field_content' => ['field_title' => 'Second', 'field_password' => 'hidden-second']]]]);
        self::assertStringNotContainsString('hidden-first', json_encode($proposal));
        self::assertStringNotContainsString('hidden-second', json_encode($proposal));
        $result = $this->apply($proposal);
        self::assertSame('Second', $GLOBALS['native_meta'][11]['root_1_content_title']);
        $this->undo($result);
        self::assertEquals($before, $GLOBALS['native_meta'][11]);
    }

    public function test_clone_loaded_prefixed_names_are_not_prefixed_twice_and_restore_absent_reference(): void
    {
        $GLOBALS['native_fields'] = [$this->field('clone', 'root', [$this->field('text', 'root_heading')]) + ['display' => 'group', 'prefix_name' => 1]];
        $GLOBALS['native_meta'][11] = ['root_heading' => 'Original'];
        $before = $GLOBALS['native_meta'][11];
        $result = $this->apply($this->proposal([['op' => 'set', 'path' => ['field_root_heading'], 'value' => 'Changed']]));
        self::assertSame('Changed', $GLOBALS['native_meta'][11]['root_heading']);
        self::assertArrayNotHasKey('root_root_heading', $GLOBALS['native_meta'][11]);
        $this->undo($result);
        self::assertEquals($before, $GLOBALS['native_meta'][11]);
    }

    public function test_removing_the_last_repeater_row_accepts_native_false_empty_and_can_restore(): void
    {
        $GLOBALS['native_fields'] = [$this->field('repeater', 'root', [$this->field('text', 'title')])];
        \update_field('field_root', [['field_title' => 'Last']], 11);
        $before = $GLOBALS['native_meta'][11];
        try {
            $result = $this->apply($this->proposal([['op' => 'remove', 'path' => [], 'index' => 0]]));
        } catch (\Throwable $error) {
            self::fail($error->getMessage());
        }
        self::assertFalse(\get_field('field_root', 11, false));
        $this->undo($result);
        self::assertEquals($before, $GLOBALS['native_meta'][11]);
    }

    public function test_backslashes_and_quoted_text_survive_native_wordpress_unslashing_on_write_and_undo(): void
    {
        $GLOBALS['native_fields'] = [$this->field('group', 'root', [$this->field('text', 'title'), $this->field('password', 'password')])];
        $GLOBALS['native_meta'][11] = ['root' => '', '_root' => 'field_root', 'root_title' => 'C:\\old\\file "quoted"', '_root_title' => 'field_title', 'root_password' => 'old\\secret', '_root_password' => 'field_password'];
        $before = $GLOBALS['native_meta'][11];
        try {
            $result = $this->apply($this->proposal([['op' => 'set', 'path' => ['field_title'], 'value' => 'C:\\new\\file "quoted"']]));
            self::assertSame('C:\\new\\file "quoted"', $GLOBALS['native_meta'][11]['root_title']);
            self::assertSame('old\\secret', $GLOBALS['native_meta'][11]['root_password']);
            $this->undo($result);
        } catch (\Throwable $error) {
            self::fail($error->getMessage());
        }
        self::assertEquals($before, $GLOBALS['native_meta'][11]);
    }

    public function test_stale_nested_reference_is_detected_even_when_the_visible_value_is_unchanged(): void
    {
        $GLOBALS['native_fields'] = [$this->field('group', 'root', [$this->field('text', 'title')])];
        \update_field('field_root', ['field_title' => 'Old'], 11);
        $proposal = $this->proposal([['op' => 'set', 'path' => ['field_title'], 'value' => 'New']]);
        $GLOBALS['native_meta'][11]['_root_title'] = 'field_replacement';
        $writes = $GLOBALS['native_writes'];
        try {
            $this->apply($proposal);
            self::fail('A reference changed after preparation');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame(-32602, $error->getCode());
        }
        self::assertSame($writes, $GLOBALS['native_writes']);
        self::assertSame('Old', $GLOBALS['native_meta'][11]['root_title']);
    }
}
