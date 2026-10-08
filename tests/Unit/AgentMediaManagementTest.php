<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AgentMediaManagementTest extends TestCase
{
    private function execute(array $calls, array $config = []): array
    {
        $command = [PHP_BINARY];
        if (! empty($config['without_mbstring'])) {
            $command = [...$command, '-d', 'disable_functions=mb_strlen'];
        }
        $process = new Process([...$command, __DIR__.'/../Support/media-management-harness.php']);
        $process->setInput(json_encode($config + ['calls' => $calls], JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function update(array $values, array $expected, string $tool = 'wp_media_update'): array
    {
        return ['tool' => $tool, 'args' => ['id' => $tool === 'wp_media_update' ? 11 : 1, 'values' => $values, 'expected' => $expected]];
    }

    public function test_read_exposes_editable_fields_without_private_paths_or_hidden_taxonomies(): void
    {
        $result = $this->execute([['tool' => 'wp_media_get', 'args' => ['id' => 11]]])['results'][0];

        $this->assertSame('original.jpg', $result['filename']);
        $this->assertFalse($result['filename_rename_supported']);
        $this->assertSame(['media_folder', 'media_tag'], array_column($result['editable_taxonomies'], 'name'));
        $this->assertStringNotContainsString('/private/path', json_encode($result));
        $this->assertArrayNotHasKey('secret_taxonomy', $result['values']['terms']);
    }

    public function test_metadata_and_organization_can_be_changed_and_restored_without_changing_urls(): void
    {
        $result = $this->execute([
            $this->update([
                'title' => 'A new title', 'alt' => 'A new alt', 'parent_id' => 22,
                'terms' => ['media_folder' => [8]],
            ], [
                'title' => 'Before', 'alt' => 'Old alt', 'parent_id' => 0,
                'terms' => ['media_folder' => [7]],
            ]),
            ['tool' => 'restore_previous', 'write_tool' => 'wp_media_update'],
            ['tool' => 'wp_media_get', 'args' => ['id' => 11]],
        ]);

        $this->assertTrue($result['results'][0]['changed']);
        $this->assertSame(['title', 'alt', 'parent_id', 'terms'], array_keys($result['results'][0]['before']));
        $this->assertSame(['media_folder' => [7]], $result['results'][0]['before']['terms']);
        $this->assertSame('Before', $result['results'][2]['values']['title']);
        $this->assertSame('Old alt', $result['results'][2]['values']['alt']);
        $this->assertSame(['media_folder' => [7], 'media_tag' => [9]], $result['results'][2]['values']['terms']);
        $this->assertSame('https://example.test/uploads/original.jpg', $result['results'][2]['url']);
    }

    public function test_changed_snapshot_refuses_all_writes(): void
    {
        foreach ([
            $this->update(['title' => 'New', 'alt' => 'New alt'], ['title' => 'Stale title', 'alt' => 'Old alt']),
            $this->update(['terms' => ['media_folder' => [8]]], ['terms' => ['media_folder' => []]]),
        ] as $call) {
            $result = $this->execute([$call]);
            $this->assertSame(-32009, $result['results'][0]['error']);
            $this->assertSame([], $result['writes']);
        }
    }

    public function test_missing_snapshot_unsafe_fields_and_invalid_organization_refuse_before_writing(): void
    {
        foreach ([
            $this->update(['title' => 'New'], []),
            $this->update(['filename' => 'new.jpg'], ['filename' => 'original.jpg']),
            $this->update(['guid' => 'https://evil.test'], ['guid' => 'old']),
            $this->update(['terms' => ['secret_taxonomy' => [8]]], ['terms' => ['secret_taxonomy' => []]]),
            $this->update(['terms' => ['media_folder' => [666]]], ['terms' => ['media_folder' => [7]]]),
            $this->update(['parent_id' => 999], ['parent_id' => 0]),
        ] as $call) {
            $result = $this->execute([$call]);
            $this->assertSame(-32602, $result['results'][0]['error']);
            $this->assertSame([], $result['writes']);
        }
    }

    public function test_alt_for_non_images_is_refused_and_html_is_sanitized(): void
    {
        $pdf = $this->execute([$this->update(['alt' => 'Picture'], ['alt' => 'Old alt'])], ['mime' => 'application/pdf']);
        $this->assertSame(-32602, $pdf['results'][0]['error']);
        $this->assertSame([], $pdf['writes']);

        $image = $this->execute([$this->update(['description' => '<script>alert(1)</script><p>Safe</p>'], ['description' => 'Description'])]);
        $this->assertSame('<p>Safe</p>', $image['results'][0]['after']['description']);
    }

    public function test_noop_and_quoted_text_have_accurate_snapshots(): void
    {
        $noop = $this->execute([$this->update(['title' => 'Before'], ['title' => 'Before'])]);
        $this->assertFalse($noop['results'][0]['changed']);
        $this->assertSame([], $noop['writes']);

        $quoted = $this->execute([$this->update(['title' => 'My \\ art "gallery"'], ['title' => 'Before'])]);
        $this->assertSame('My \\ art "gallery"', $quoted['results'][0]['after']['title']);
    }

    public function test_failed_media_write_restores_earlier_fields_and_does_not_claim_success(): void
    {
        foreach ([['fail_taxonomy' => 'media_folder'], ['fail_alt' => true]] as $config) {
            $result = $this->execute([
                $this->update([
                    'title' => 'New title', 'alt' => 'New alt', 'terms' => ['media_folder' => [8]],
                ], [
                    'title' => 'Before', 'alt' => 'Old alt', 'terms' => ['media_folder' => [7]],
                ]),
                ['tool' => 'wp_media_get', 'args' => ['id' => 11]],
            ], $config);
            $this->assertSame(-32000, $result['results'][0]['error']);
            $this->assertSame('Before', $result['results'][1]['values']['title']);
            $this->assertSame('Old alt', $result['results'][1]['values']['alt']);
            $this->assertSame([7], $result['results'][1]['values']['terms']['media_folder']);
        }
    }

    public function test_legacy_text_that_cannot_be_restored_exactly_refuses_before_any_write(): void
    {
        foreach ([
            'title' => '<b>Logo</b>', 'alt' => '  Original alt  ',
            'caption' => '<script>legacy()</script>Caption',
            'description' => '<script>legacy()</script><p>Description</p>',
        ] as $field => $original) {
            $result = $this->execute([
                $this->update([$field => 'New value', 'parent_id' => 22], [$field => $original, 'parent_id' => 0]),
                ['tool' => 'wp_media_get', 'args' => ['id' => 11]],
            ], ['initial' => [$field => $original]]);

            $this->assertSame(-32602, $result['results'][0]['error']);
            $this->assertSame([], $result['writes']);
            $this->assertSame($original, $result['results'][1]['values'][$field]);
            $this->assertSame(0, $result['results'][1]['values']['parent_id']);
        }
    }

    public function test_multibyte_text_does_not_require_the_optional_mbstring_extension(): void
    {
        $title = str_repeat('אב', 400);
        $result = $this->execute([
            $this->update(['title' => $title], ['title' => 'Before']),
        ], ['without_mbstring' => true]);

        $this->assertSame($title, $result['results'][0]['after']['title']);
    }

    public function test_optimole_presence_connection_and_secret_redaction(): void
    {
        $absent = $this->execute([['tool' => 'wp_optimole_get']])['results'][0];
        $this->assertFalse($absent['active']);
        $this->assertSame([], $absent['values']);

        $active = $this->execute([['tool' => 'wp_optimole_get']], ['optimole' => true])['results'][0];
        $this->assertTrue($active['active']);
        $this->assertTrue($active['connected']);
        $this->assertSame(80, $active['values']['quality']);
        $this->assertStringNotContainsString('must-never-leak', json_encode($active));
        $this->assertArrayNotHasKey('api_key', $active['values']);
        $this->assertArrayNotHasKey('service_data', $active['values']);
    }

    public function test_optimole_update_and_undo_preserve_unrelated_and_secret_settings(): void
    {
        $result = $this->execute([
            $this->update(['quality' => 90, 'lazyload' => 'enabled'], ['quality' => 80, 'lazyload' => 'disabled'], 'wp_optimole_update'),
            ['tool' => 'restore_previous', 'write_tool' => 'wp_optimole_update'],
        ], ['optimole' => true]);

        $this->assertSame(['quality' => 80, 'lazyload' => 'disabled'], $result['results'][0]['before']);
        $this->assertSame(['quality' => 90, 'lazyload' => 'enabled'], $result['results'][0]['after']);
        $this->assertSame(80, $result['options']['quality']);
        $this->assertSame('disabled', $result['options']['lazyload']);
        $this->assertSame('must-never-leak-api-secret', $result['options']['api_key']);
        $this->assertSame(['keep' => true], $result['options']['unknown_future_setting']);
    }

    public function test_optimole_stale_values_and_unsafe_settings_are_refused(): void
    {
        foreach ([
            [$this->update(['quality' => 90], ['quality' => 60], 'wp_optimole_update'), -32009],
            [$this->update(['quality' => 20], ['quality' => 80], 'wp_optimole_update'), -32602],
            [$this->update(['lazyload' => true], ['lazyload' => 'disabled'], 'wp_optimole_update'), -32602],
            [$this->update(['api_key' => 'replacement'], ['api_key' => 'old'], 'wp_optimole_update'), -32602],
            [$this->update(['offload_media' => 'enabled'], ['offload_media' => 'disabled'], 'wp_optimole_update'), -32602],
        ] as [$call, $error]) {
            $result = $this->execute([$call], ['optimole' => true]);
            $this->assertSame($error, $result['results'][0]['error']);
            $this->assertSame([], $result['writes']);
        }
    }

    public function test_optimole_env_locks_disconnection_and_absence_are_respected(): void
    {
        foreach ([[], ['optimole' => true, 'disconnected' => true], ['optimole' => true, 'env_locked' => true]] as $config) {
            $result = $this->execute([$this->update(['quality' => 90], ['quality' => 80], 'wp_optimole_update')], $config);
            $this->assertSame(-32602, $result['results'][0]['error']);
            $this->assertSame([], $result['writes']);
        }
    }

    public function test_failed_optimole_write_restores_preceding_settings(): void
    {
        $result = $this->execute([
            $this->update(['quality' => 90, 'lazyload' => 'enabled'], ['quality' => 80, 'lazyload' => 'disabled'], 'wp_optimole_update'),
        ], ['optimole' => true, 'fail_setting' => 'lazyload']);

        $this->assertSame(-32000, $result['results'][0]['error']);
        $this->assertSame(80, $result['options']['quality']);
        $this->assertSame('disabled', $result['options']['lazyload']);
    }

    public function test_schemas_declare_required_snapshots_and_only_explicit_write_fields(): void
    {
        $definitions = $this->execute([['tool' => 'definitions']])['results'][0];
        $tools = array_column($definitions, null, 'name');
        foreach (['wp_media_update', 'wp_optimole_update'] as $tool) {
            $this->assertSame(['id', 'values', 'expected'], $tools[$tool]['inputSchema']['required']);
            $this->assertFalse($tools[$tool]['inputSchema']['properties']['values']['additionalProperties']);
            $this->assertFalse($tools[$tool]['annotations']['destructiveHint']);
        }
        $this->assertArrayNotHasKey('filename', $tools['wp_media_update']['inputSchema']['properties']['values']['properties']);
        $this->assertArrayNotHasKey('api_key', $tools['wp_optimole_update']['inputSchema']['properties']['values']['properties']);
    }
}
