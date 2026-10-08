<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AgentLegacyFieldsSafetyTest extends TestCase
{
    private function execute(array $input): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../Support/legacy-fields-harness.php']);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function field(string $name, string $type = 'text', array $extra = []): array
    {
        return ['key' => 'field_'.$name, 'name' => $name, 'type' => $type, 'label' => $name] + $extra;
    }

    public function test_legacy_acf_reads_use_unformatted_schema_values_and_hide_nested_credentials(): void
    {
        $result = $this->execute([
            'acf' => true, 'action' => 'read',
            'definitions' => [
                $this->field('title'), $this->field('apiKey'), $this->field('password'),
                $this->field('access', 'password'),
                $this->field('details', 'group', ['sub_fields' => [$this->field('caption'), $this->field('access', 'password'), $this->field('apiKey')]]),
                $this->field('rows', 'repeater', ['sub_fields' => [$this->field('caption'), $this->field('access', 'password')]]),
                $this->field('vendor', 'unknown_vendor_type'),
            ],
            'values' => [
                'field_title' => 'Public value', 'field_apiKey' => 'TOP-SECRET', 'field_password' => 'PLAIN-PASSWORD',
                'field_access' => 'ACF-PASSWORD',
                'field_details' => ['caption' => 'Visible', 'access' => 'NESTED-PASSWORD', 'apiKey' => 'NESTED-KEY', 'unknown' => 'UNKNOWN-SECRET'],
                'field_rows' => [['field_caption' => 'Row', 'field_access' => 'ROW-PASSWORD']],
                'field_vendor' => ['unregistered' => 'VENDOR-SECRET'],
            ],
            'meta' => ['title' => 'Wrong fallback', 'details_api_key' => 'RAW-SECRET'],
        ]);

        $this->assertSame('Public value', $result['result']['title']);
        $this->assertSame('Visible', $result['result']['details']['field_caption']);
        $this->assertSame('Row', $result['result']['rows'][0]['field_caption']);
        $this->assertArrayNotHasKey('vendor', $result['result']);
        $this->assertArrayNotHasKey('apiKey', $result['result']);
        $this->assertArrayNotHasKey('password', $result['result']);
        $this->assertArrayNotHasKey('access', $result['result']);
        foreach (['TOP-SECRET', 'PLAIN-PASSWORD', 'ACF-PASSWORD', 'NESTED-PASSWORD', 'NESTED-KEY', 'UNKNOWN-SECRET', 'ROW-PASSWORD', 'VENDOR-SECRET', 'RAW-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
        }
        foreach ($result['reads'] as $read) {
            $this->assertFalse($read['format']);
        }
    }

    public function test_legacy_acf_writes_cannot_bypass_typed_approval_even_for_simple_fields(): void
    {
        foreach ([['title' => 'Replacement'], ['rows' => [['access' => 'secret']]], ['unknown_key' => 'value']] as $fields) {
            $result = $this->execute(['acf' => true, 'action' => 'update', 'fields' => $fields]);
            $this->assertSame(-32602, $result['code']);
            $this->assertStringContainsString('wp_acf_prepare', $result['error']);
            $this->assertSame([], $result['writes']);
        }
    }

    public function test_missing_schema_dependency_never_falls_back_to_raw_acf_storage(): void
    {
        $result = $this->execute(['acf' => true, 'without_schema' => true, 'action' => 'read', 'meta' => ['rows_0_password' => 'SECRET']]);
        $this->assertSame([], $result['result']);
        $this->assertSame([], $result['reads']);
    }

    public function test_legacy_schema_omits_password_and_secret_fields_without_losing_public_names(): void
    {
        $result = $this->execute([
            'acf' => true, 'action' => 'schema',
            'definitions' => [$this->field('title'), $this->field('access', 'password'), $this->field('apiKey'), $this->field('password')],
        ]);
        $this->assertSame(['title'], array_column($result['result'], 'key'));
        $this->assertSame('field_title', $result['result'][0]['native_field_key']);
    }

    public function test_plain_meta_keeps_jetengine_values_and_filters_nested_secrets(): void
    {
        $result = $this->execute([
            'action' => 'read',
            'meta' => ['price' => 10, '_edit_lock' => 'LOCK', 'apiKey' => 'SECRET', 'details' => ['public' => 'text', 'rows' => [['caption' => 'Visible', 'password' => 'SECRET']]]],
        ]);
        $this->assertSame(['price' => 10, 'details' => ['public' => 'text', 'rows' => [['caption' => 'Visible']]]], $result['result']);
    }

    public function test_plain_meta_writes_preserve_the_snapshot_contract(): void
    {
        $result = $this->execute(['action' => 'update', 'meta' => ['price' => 10], 'fields' => ['price' => 20, 'location' => ['city' => 'Tel Aviv']]]);
        $this->assertSame(['updated' => ['price', 'location'], 'previous' => ['price' => 10, 'location' => '']], $result['result']);
        $this->assertCount(2, $result['writes']);
    }

    public function test_protected_new_or_existing_meta_blocks_every_write_before_mutation(): void
    {
        foreach ([
            ['meta' => [], 'fields' => ['title' => 'New', 'details' => ['rows' => [['accessToken' => 'NEW-SECRET']]]]],
            ['meta' => ['details' => ['password' => 'OLD-SECRET']], 'fields' => ['title' => 'New', 'details' => ['caption' => 'New']]],
        ] as $case) {
            $result = $this->execute(['action' => 'update'] + $case);
            $this->assertSame(-32602, $result['code']);
            $this->assertSame([], $result['writes']);
            $this->assertStringNotContainsString('OLD-SECRET', json_encode($result));
            $this->assertStringNotContainsString('NEW-SECRET', json_encode($result));
        }
    }
}
