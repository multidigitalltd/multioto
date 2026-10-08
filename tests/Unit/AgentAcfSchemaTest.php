<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AgentAcfSchemaTest extends TestCase
{
    private function field(string $type, string $name = 'example', array $extra = []): array
    {
        return $extra + ['key' => 'field_'.$name, 'name' => $name, 'type' => $type, 'label' => ucfirst($name)];
    }

    private function runCalls(array $calls, array $config = []): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../Support/acf-schema-harness.php']);
        $process->setInput(json_encode($config + ['calls' => $calls], JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function read(array $config, array $args = [], string $tool = 'wp_acf_get'): array
    {
        return $this->runCalls([['tool' => $tool, 'args' => $args + ['context' => 'post', 'id' => 11]]], $config);
    }

    public function test_every_native_stored_field_type_has_an_explicit_editable_schema(): void
    {
        $types = ['text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'wysiwyg', 'oembed', 'image', 'file', 'gallery', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'link', 'post_object', 'relationship', 'taxonomy', 'user', 'page_link', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'icon_picker', 'google_map', 'group', 'repeater', 'flexible_content', 'clone'];
        $fields = array_map(fn (string $type): array => $this->field($type, $type, $type === 'clone' ? ['sub_fields' => [$this->field('text', 'cloned')]] : []), $types);
        $result = $this->read(['fields' => $fields], [], 'wp_acf_schema')['results'][0];

        $this->assertSame($types, array_column($result['fields'], 'type'));
        $this->assertSame(array_fill(0, count($types), true), array_column($result['fields'], 'editable'));
    }

    public function test_real_post_location_and_unformatted_acf_keys_are_used(): void
    {
        $result = $this->read(['fields' => [$this->field('image', 'hero')], 'values' => ['field_hero' => 45], 'with_state' => true]);

        $this->assertSame(['post_id' => 11, 'post_type' => 'page', 'post_status' => 'publish', 'page_template' => 'template-property.php'], $result['screens'][0]);
        $this->assertSame([['field_hero', 11, false]], $result['reads']);
        $this->assertSame(['field_hero' => 45], $result['results'][0]['values']);
        $this->assertSame(['version' => 'version-field_hero', 'token' => 'opaque-field_hero'], $result['results'][0]['snapshots']['field_hero']);
    }

    public function test_registered_options_user_and_term_contexts_resolve_without_arbitrary_storage_ids(): void
    {
        $result = $this->runCalls([
            ['tool' => 'wp_acf_options_pages'],
            ['tool' => 'wp_acf_schema', 'args' => ['context' => 'user', 'id' => 21]],
            ['tool' => 'wp_acf_schema', 'args' => ['context' => 'term', 'id' => 31]],
            ['tool' => 'wp_acf_schema', 'args' => ['context' => 'options', 'options_page' => 'custom-settings']],
            ['tool' => 'wp_acf_schema', 'args' => ['context' => 'options', 'options_page' => 'wp_options']],
        ]);

        $this->assertSame(['site-settings', 'custom-settings', 'redirect-page'], array_column($result['results'][0]['pages'], 'options_page'));
        $this->assertSame('user_21', $result['results'][1]['target']['acf_id']);
        $this->assertSame('term_31', $result['results'][2]['target']['acf_id']);
        $this->assertSame('custom_storage', $result['results'][3]['target']['acf_id']);
        $this->assertSame(-32602, $result['results'][4]['error']);
        $this->assertSame(['user_id' => 21, 'user_form' => 'edit'], $result['screens'][0]);
        $this->assertSame(['taxonomy' => 'category', 'term_id' => 31], $result['screens'][1]);
        $this->assertSame(['options_page' => 'custom-settings'], $result['screens'][2]);
    }

    public function test_privileged_users_hidden_taxonomies_and_internal_posts_are_not_available(): void
    {
        foreach ([
            [['context' => 'user', 'id' => 21], ['admin' => true]],
            [['context' => 'user', 'id' => 21], ['superadmin' => true]],
            [['context' => 'term', 'id' => 31], ['hidden_taxonomy' => true]],
            [['context' => 'post', 'id' => 11], ['post_type' => 'acf-field-group']],
            [['context' => 'post', 'id' => '11'], []],
            [['context' => 'options', 'options_page' => 'user-store'], []],
        ] as [$args, $config]) {
            $result = $this->read($config, $args);
            $this->assertSame(-32602, $result['results'][0]['error']);
            $this->assertSame([], $result['reads']);
        }
    }

    public function test_passwords_and_secret_named_fields_are_masked_recursively_without_losing_public_siblings(): void
    {
        $fields = [$this->field('repeater', 'rows', ['sub_fields' => [
            $this->field('text', 'title'), $this->field('password', 'password'), $this->field('text', 'serviceApiKey'),
        ]]), $this->field('text', 'auth_token'), $this->field('text', 'password_hash')];
        $result = $this->read(['fields' => $fields, 'values' => [
            'field_rows' => [['field_title' => 'Public', 'field_password' => 'PRIVATE-PASSWORD', 'field_serviceApiKey' => 'PRIVATE-KEY', 'unregistered' => 'PRIVATE-UNKNOWN']],
            'field_auth_token' => 'PRIVATE-TOKEN', 'field_password_hash' => 'PRIVATE-HASH',
        ]])['results'][0];

        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $this->assertSame([['field_title' => 'Public', 'field_password' => '[מוסתר]', 'field_serviceApiKey' => '[מוסתר]']], $result['values']['field_rows']);
        $this->assertTrue($result['fields'][0]['sub_fields'][1]['editable']);
        $this->assertFalse($result['fields'][0]['sub_fields'][2]['editable']);
        $this->assertArrayNotHasKey('field_auth_token', $result['values']);
    }

    public function test_nested_flexible_group_and_prefixed_clone_keep_exact_loaded_keys(): void
    {
        $clone = $this->field('clone', 'hero', ['display' => 'seamless', 'prefix_name' => 1]);
        $loadedClone = $clone + ['sub_fields' => [$this->field('text', 'hero_heading', ['key' => 'field_hero_field_heading'])]];
        $flex = $this->field('flexible_content', 'sections', ['layouts' => [[
            'key' => 'layout_hero', 'name' => 'hero', 'label' => 'Hero', 'min' => 0, 'max' => 2,
            'sub_fields' => [$this->field('group', 'content', ['sub_fields' => [$this->field('text', 'title')]])],
        ]]]);
        $result = $this->read(['fields' => [$clone, $flex], 'loaded' => ['field_hero' => $loadedClone], 'values' => [
            'field_hero' => ['field_hero_field_heading' => 'Hello'],
            'field_sections' => [['acf_fc_layout' => 'hero', 'field_content' => ['field_title' => 'Nested']]],
        ]])['results'][0];

        $this->assertSame('field_hero_field_heading', $result['fields'][0]['sub_fields'][0]['key']);
        $this->assertSame('hero_heading', $result['fields'][0]['sub_fields'][0]['name']);
        $this->assertSame(['field_hero_field_heading' => 'Hello'], $result['values']['field_hero']);
        $this->assertSame([['acf_fc_layout' => 'hero', 'field_content' => ['field_title' => 'Nested']]], $result['values']['field_sections']);
        $this->assertSame(2, $result['fields'][1]['layouts'][0]['max']);
    }

    public function test_layout_only_unresolved_clone_and_third_party_types_are_explicitly_readonly(): void
    {
        $result = $this->read(['fields' => [
            $this->field('message', ''), $this->field('tab', 'tab'), $this->field('accordion', 'accordion'), $this->field('separator', 'separator'), $this->field('output', 'output'),
            $this->field('vendor_opaque', 'custom'), $this->field('clone', 'missing'),
        ], 'values' => ['field_custom' => ['secret' => 'PRIVATE']]])['results'][0];

        $this->assertSame(['layout_only', 'layout_only', 'layout_only', 'layout_only', 'layout_only', 'unsupported_custom_type', 'unresolved_clone'], array_column($result['fields'], 'reason'));
        $this->assertSame([], $result['values']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function test_large_values_are_never_silently_truncated_and_field_selection_is_honored(): void
    {
        $config = ['fields' => [$this->field('textarea', 'large'), $this->field('text', 'small')], 'values' => ['field_large' => str_repeat('x', 270000), 'field_small' => 'OK']];
        $this->assertSame(-32602, $this->read($config)['results'][0]['error']);
        $selected = $this->read($config, ['field_key' => 'field_small']);
        $this->assertSame(['field_small' => 'OK'], $selected['results'][0]['values']);
        $this->assertSame([['field_small', 11, false]], $selected['reads']);
        $this->assertSame(-32602, $this->read($config, ['field_key' => 'field_foreign'])['results'][0]['error']);
    }

    public function test_unknown_flexible_layout_and_invalid_scalar_object_shape_are_refused(): void
    {
        $flex = $this->field('flexible_content', 'sections', ['layouts' => []]);
        $this->assertSame(-32602, $this->read(['fields' => [$flex], 'values' => ['field_sections' => [['acf_fc_layout' => 'deleted', 'password' => 'private']]]])['results'][0]['error']);
        $this->assertSame(-32602, $this->read(['fields' => [$this->field('text')], 'values' => ['field_example' => ['password' => 'private']]])['results'][0]['error']);
    }
}
