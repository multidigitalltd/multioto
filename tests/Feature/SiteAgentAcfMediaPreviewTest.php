<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteAgentAcfActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SiteAgentAcfMediaPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private array $field = ['key' => 'field_media', 'label' => 'גלריה', 'type' => 'gallery'];

    private mixed $before = [90];

    private mixed $after = [91];

    private array $media = [];

    private array $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        $this->site = Site::factory()->create([
            'mcp_enabled' => true, 'mcp_secret' => 'site-secret',
            'mcp_capabilities' => ['tools' => array_map(fn (string $name): array => ['name' => $name], [
                'wp_acf_get', 'wp_acf_prepare', 'wp_acf_update', 'wp_media_get',
            ])],
        ]);
        $this->media = [
            90 => ['id' => 90, 'label' => 'לוגו', 'values' => ['title' => 'לוגו']],
            91 => ['id' => 91, 'label' => 'חולצה', 'values' => ['title' => 'חולצה']],
        ];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args): array {
            $this->assertSame($this->site->id, $site->id);
            if ($tool === 'wp_acf_get') {
                return ['target' => $this->target(), 'fields' => [$this->field], 'snapshots' => ['field_media' => $this->seal('before')]];
            }
            if ($tool === 'wp_acf_prepare') {
                return ['target' => $this->target(), 'field_key' => 'field_media', 'changed' => true,
                    'before' => $this->before, 'after' => $this->after,
                    'expected' => $this->seal('before'), 'prepared' => $this->seal('after'), 'notes' => []];
            }
            if ($tool === 'wp_media_get') {
                $this->assertSame(['id'], array_keys($args));
                $this->lookups[] = $args['id'];
                $result = $this->media[$args['id']] ?? new RuntimeException('private-lookup-error');
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
            throw new RuntimeException('Unexpected mutating call: '.$tool);
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result): string => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->app->instance(McpClient::class, $mcp);
    }

    public function test_gallery_replacement_identifies_both_images_without_changing_sealed_plan(): void
    {
        $this->media[90] += ['url' => 'https://private.test/original-secret.png', 'filename' => 'private-file.png'];
        $this->media[90]['values'] += ['alt' => 'private-alt', 'description' => 'private-description', 'caption' => 'private-caption'];

        $offer = $this->propose();

        $this->assertStringContainsString('״לוגו״ (מדיה #90) ← ״חולצה״ (מדיה #91)', $offer['preview']);
        $this->assertStringContainsString('מיקום השדה: post:43 / field_media', $offer['preview']);
        $this->assertSame([90, 91], $this->lookups);
        $this->assertSame($this->seal('before'), $offer['plan']['expected']);
        $this->assertSame($this->seal('after'), $offer['plan']['prepared']);
        foreach (['original-secret.png', 'private-file.png', 'private-alt', 'private-description', 'private-caption', 'sealed-before', 'sealed-after'] as $secret) {
            $this->assertStringNotContainsString($secret, $offer['preview']);
        }
    }

    public function test_gallery_clear_explicitly_shows_an_empty_gallery_instead_of_another_image(): void
    {
        $this->after = [];

        $offer = $this->propose();

        $this->assertStringContainsString('לוגו', $offer['preview']);
        $this->assertStringContainsString('← (גלריה ריקה)', $offer['preview']);
        $this->assertStringNotContainsString('חולצה', $offer['preview']);
        $this->assertSame([90], $this->lookups);
    }

    #[DataProvider('scalarMediaFields')]
    public function test_image_and_file_fields_use_titles_and_explicit_empty_values(string $type, mixed $before, mixed $after, string $expected): void
    {
        $this->field['type'] = $type;
        $this->before = $before;
        $this->after = $after;

        $offer = $this->propose();

        $this->assertStringContainsString($expected, $offer['preview']);
        $this->assertSame([90], $this->lookups);
    }

    public static function scalarMediaFields(): array
    {
        return [
            'clear image' => ['image', 90, 0, '״לוגו״ (מדיה #90) ← (אין תמונה)'],
            'insert image' => ['image', null, '90', '(אין תמונה) ← ״לוגו״ (מדיה #90)'],
            'clear file' => ['file', '90', false, '״לוגו״ (מדיה #90) ← (אין קובץ)'],
        ];
    }

    #[DataProvider('unreliableMediaResponses')]
    public function test_failed_or_unreliable_media_lookup_preserves_exact_id_without_using_unverified_title(mixed $response): void
    {
        $this->media[90] = $response;
        $this->after = [];

        $offer = $this->propose();

        $this->assertArrayHasKey('plan', $offer);
        $this->assertStringContainsString('90', $offer['preview']);
        $this->assertStringContainsString('גלריה ריקה', $offer['preview']);
        $this->assertStringNotContainsString('untrusted-title', $offer['preview']);
        $this->assertStringNotContainsString('private-lookup-error', $offer['preview']);
    }

    public static function unreliableMediaResponses(): array
    {
        return [
            'wrong id' => [['id' => 91, 'values' => ['title' => 'untrusted-title']]],
            'missing id' => [['values' => ['title' => 'untrusted-title']]],
            'string id' => [['id' => '90', 'values' => ['title' => 'untrusted-title']]],
            'empty title' => [['id' => 90, 'values' => ['title' => '  ']]],
            'malformed title' => [['id' => 90, 'values' => ['title' => ['untrusted-title']]]],
            'remote failure' => [new RuntimeException('private-lookup-error')],
        ];
    }

    public function test_absent_media_capability_keeps_ids_without_lookup(): void
    {
        $this->site->update(['mcp_capabilities' => ['tools' => array_map(fn (string $name): array => ['name' => $name], [
            'wp_acf_get', 'wp_acf_prepare', 'wp_acf_update',
        ])]]);

        $offer = $this->propose();

        $this->assertStringContainsString('90 ← 91', $offer['preview']);
        $this->assertSame([], $this->lookups);
    }

    public function test_nested_gallery_is_qualified_but_unrelated_number_and_password_are_not_looked_up(): void
    {
        $this->field = ['key' => 'field_media', 'label' => 'Sections', 'type' => 'flexible_content', 'layouts' => [[
            'name' => 'photos', 'sub_fields' => [
                ['key' => 'field_gallery', 'label' => 'Gallery', 'type' => 'gallery'],
                ['key' => 'field_count', 'label' => 'Count', 'type' => 'number'],
                ['key' => 'field_secret', 'label' => 'Secret', 'type' => 'password'],
            ],
        ]]];
        $this->before = [['acf_fc_layout' => 'photos', 'field_gallery' => [90], 'field_count' => 701, 'field_secret' => ['redacted' => true]]];
        $this->after = [['acf_fc_layout' => 'photos', 'field_gallery' => [91], 'field_count' => 702, 'field_secret' => ['redacted' => true]]];

        $offer = $this->propose();

        $this->assertStringContainsString('״לוגו״ (מדיה #90) ← ״חולצה״ (מדיה #91)', $offer['preview']);
        $this->assertStringContainsString('Count: 701 ← 702', $offer['preview']);
        $this->assertSame([90, 91], $this->lookups);
    }

    public function test_at_most_thirty_distinct_ids_are_looked_up_once_per_offer_even_when_the_preview_is_too_large(): void
    {
        $this->before = range(1, 40);
        $this->after = range(2, 41);
        foreach (range(1, 41) as $id) {
            $this->media[$id] = ['id' => $id, 'values' => ['title' => 'file-'.$id]];
        }

        $offer = $this->propose();

        $this->assertSame(range(1, 30), $this->lookups);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertStringContainsString('40 ← 41', $offer['preview']);
        $this->assertStringNotContainsString('file-31', $offer['preview']);
    }

    public function test_media_titles_are_plain_single_line_bounded_display_text(): void
    {
        $this->media[90]['values']['title'] = "<b>לוגו</b>\n\tשל &lt;i&gt;החברה&lt;/i&gt;";
        $this->media[91]['values']['title'] = str_repeat('א', 300);

        $offer = $this->propose();

        $this->assertStringContainsString('״לוגו של החברה״ (מדיה #90)', $offer['preview']);
        $this->assertStringNotContainsString('<b>', $offer['preview']);
        $this->assertStringNotContainsString(str_repeat('א', 161), $offer['preview']);
        $this->assertStringContainsString('…״ (מדיה #91)', $offer['preview']);
    }

    public function test_unchanged_gallery_entries_do_not_consume_lookups_needed_to_identify_the_replacement(): void
    {
        $this->before = range(1, 40);
        $this->after = [...range(1, 39), 91];
        $this->media[40] = ['id' => 40, 'values' => ['title' => 'לוגו קודם']];

        $offer = $this->propose();

        $this->assertSame([40, 91], $this->lookups);
        $this->assertStringContainsString('״לוגו קודם״ (מדיה #40) ← ״חולצה״ (מדיה #91)', $offer['preview']);
    }

    private function propose(): array
    {
        return app(SiteAgentAcfActions::class)->propose($this->site, [
            'context' => 'post', 'id' => 43, 'field_key' => 'field_media',
            'operations' => [['op' => 'set', 'path' => [], 'value' => $this->after]],
        ], ['acf:post:43:field_media']);
    }

    private function target(): array
    {
        return ['context' => 'post', 'id' => 43, 'label' => 'דף הבית'];
    }

    private function seal(string $value): array
    {
        return ['version' => hash('sha256', $value), 'token' => 'sealed-'.$value];
    }
}
