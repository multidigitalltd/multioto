<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\Evaluation\EvaluationAdvancedWorld;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentToolbox;
use App\Services\SiteAgent\SiteChangeApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Exercise fixture receipts with the real production proposal/undo validators. */
class SiteAgentEvaluationAdvancedWorldTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationAdvancedWorld $world;

    private array $state;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['siteagent.assistant.disabled_permissions' => []]);
        $this->world = new EvaluationAdvancedWorld;
        $this->state = ['content' => [43 => ['id' => 43, 'type' => 'page', 'title' => 'דף הבית', 'status' => 'publish']],
            'products' => [7 => ['id' => 7, 'type' => 'simple', 'name' => 'חולצה כחולה', 'regular_price' => '100.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null],
                8 => ['id' => 8, 'type' => 'simple', 'name' => 'חולצה אדומה', 'regular_price' => '120.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null]],
            'users' => [1 => ['id' => 1], 5 => ['id' => 5]], 'media' => [90 => ['id' => 90], 91 => ['id' => 91]]];
        $this->world->seed($this->state);
        $this->site = Site::factory()->create(['mcp_enabled' => true, 'mcp_secret' => 'evaluation-secret',
            'mcp_capabilities' => ['server' => ['version' => '1.12.0'],
                'tools' => array_map(fn (string $name): array => ['name' => $name], $this->world->supportedTools())]]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $name, array $args = []): array => $this->world->handle($name, $args, $this->state) ?? throw new \RuntimeException('Unexpected fixture tool '.$name));
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
    }

    #[DataProvider('genericEdits')]
    public function test_bounded_fixture_editors_pass_real_preview_apply_and_undo(string $read, string $propose, array $args, array $values, string $path, mixed $after): void
    {
        $baseline = $this->state;
        $seen = $this->read($read, $args);
        $offer = app(SiteActionProposer::class)->propose($this->site, $propose, $args + ['values' => $values], $seen);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer, JSON_UNESCAPED_UNICODE));
        $this->assertSame($baseline, $this->state, 'Read/preparation cannot mutate the fixture.');
        $request = $this->request($offer);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame($after, data_get($this->state, $path));
        $request->restore = $result['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame($baseline, $this->state);
    }

    public static function genericEdits(): array
    {
        return [
            'site settings' => ['get_site_settings', 'propose_site_settings', [], ['blogname' => 'שם חדש'], 'settings.blogname', 'שם חדש'],
            'Yoast title' => ['get_seo', 'propose_seo_update', ['id' => 43], ['title' => 'כותרת חדשה'], 'seo.43.title', 'כותרת חדשה'],
            'Yoast fallback' => ['get_seo', 'propose_seo_update', ['id' => 43], ['description' => null], 'seo.43.description', null],
            'Optimole' => ['get_optimole', 'propose_optimole_update', [], ['quality' => 90, 'autoquality' => 'disabled'], 'optimole.quality', 90],
            'CCT row' => ['get_cct', 'propose_cct_update', ['type' => 'houses', 'id' => 301], ['price' => 2100000], 'cct.houses.301.price', 2100000],
        ];
    }

    #[DataProvider('acfEdits')]
    public function test_acf_nested_fixture_schema_values_and_seals_pass_real_app_contracts(array $selector, array $operations, string $path, mixed $after): void
    {
        $baseline = $this->state;
        $seen = $this->read('get_acf', $selector);
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_acf_update', $selector + ['operations' => $operations], $seen);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer, JSON_UNESCAPED_UNICODE));
        $this->assertSame($baseline, $this->state);
        $request = $this->request($offer);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame($after, data_get($this->state, $path));
        $request->restore = $result['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame($baseline, $this->state);
    }

    public static function acfEdits(): array
    {
        $post = ['context' => 'post', 'id' => 43];

        return [
            'text' => [$post + ['field_key' => 'field_heading'], [['op' => 'set', 'path' => [], 'value' => 'שלום לכולם']], 'acf.post.43.field_heading', 'שלום לכולם'],
            'number' => [$post + ['field_key' => 'field_score'], [['op' => 'set', 'path' => [], 'value' => 9]], 'acf.post.43.field_score', 9],
            'repeater cell' => [$post + ['field_key' => 'field_faq'], [['op' => 'set', 'path' => [0, 'field_answer'], 'value' => 'צוות מקצועי']], 'acf.post.43.field_faq.0.field_answer', 'צוות מקצועי'],
            'repeater row' => [$post + ['field_key' => 'field_faq'], [['op' => 'insert', 'path' => [], 'index' => 1, 'value' => ['field_question' => 'איפה?', 'field_answer' => 'בישראל']]], 'acf.post.43.field_faq.1.field_answer', 'בישראל'],
            'flexible' => [$post + ['field_key' => 'field_sections'], [['op' => 'set', 'path' => [0, 'field_title'], 'value' => 'הסיפור החדש']], 'acf.post.43.field_sections.0.field_title', 'הסיפור החדש'],
            'clone' => [$post + ['field_key' => 'field_contact'], [['op' => 'set', 'path' => ['field_phone'], 'value' => '03-7654321']], 'acf.post.43.field_contact.field_phone', '03-7654321'],
            'gallery' => [$post + ['field_key' => 'field_gallery'], [['op' => 'set', 'path' => [], 'value' => [90, 91]]], 'acf.post.43.field_gallery', [90, 91]],
            'options' => [['context' => 'options', 'options_page' => 'site-options', 'field_key' => 'field_footer'], [['op' => 'set', 'path' => [], 'value' => 'טקסט תחתון חדש']], 'acf.options.site-options.field_footer', 'טקסט תחתון חדש'],
        ];
    }

    public function test_acf_options_discovery_returns_the_native_selector_used_by_followup_reads(): void
    {
        $baseline = $this->state;
        $read = app(SiteAgentToolbox::class)->read($this->site, 'list_acf_options', []);
        $this->assertFalse($read['is_error'], $read['content']);
        $pages = json_decode($read['content'], true, 512, JSON_THROW_ON_ERROR)['pages'];
        $this->assertSame('site-options', $pages[0]['options_page']);
        $this->assertSame('אפשרויות האתר', $pages[0]['label']);
        $this->read('get_acf', ['context' => 'options', 'options_page' => $pages[0]['options_page'], 'field_key' => 'field_footer']);
        $this->assertSame($baseline, $this->state);
    }

    #[DataProvider('membershipKinds')]
    public function test_learndash_course_and_group_membership_contracts_apply_and_restore(string $kind, int $id, string $path): void
    {
        $baseline = $this->state;
        $args = ['user_id' => 5, 'kind' => $kind, 'target_id' => $id];
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_ld_membership', $args + ['action' => 'add'], $this->read('get_ld_membership', $args));
        $this->assertArrayHasKey('plan', $offer, json_encode($offer, JSON_UNESCAPED_UNICODE));
        $this->assertSame($baseline, $this->state);
        $request = $this->request($offer);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertTrue(data_get($this->state, $path));
        $request->restore = $result['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame($baseline, $this->state);
    }

    public static function membershipKinds(): array
    {
        return ['course' => ['course', 201, 'learndash.direct.5.201'], 'group' => ['group', 211, 'learndash.groups.5.211']];
    }

    public function test_category_campaign_uses_integer_prices_and_actual_receipts_for_apply_and_undo(): void
    {
        $before = $this->state['products'];
        $args = ['category_id' => 12, 'include_children' => true];
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_category_sale', $args + [
            'discount_type' => 'percent', 'discount_value' => '20', 'starts_at' => '2030-06-02 10:45', 'ends_at' => '2030-06-04 22:30',
        ], $this->read('get_category_sale', $args));
        $this->assertArrayHasKey('plan', $offer, json_encode($offer, JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $this->state['products']);
        $request = $this->request($offer);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame('80.00', $this->state['products'][7]['sale_price']);
        $this->assertSame('96.00', $this->state['products'][8]['sale_price']);
        $request->restore = $result['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame($before, $this->state['products']);
    }

    public function test_protected_values_admin_membership_and_unknown_tools_never_get_optimistic_success(): void
    {
        $before = $this->state;
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_acf', ['context' => 'post', 'id' => 43]);
        $this->assertFalse($read['is_error']);
        $this->assertStringNotContainsString('evaluation-private-placeholder', $read['content']);
        $args = ['user_id' => 1, 'kind' => 'course', 'target_id' => 201];
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_ld_membership', $args + ['action' => 'add'], $this->read('get_ld_membership', $args));
        $this->assertArrayHasKey('error', $offer);
        $this->assertNull($this->world->handle('delete_everything', [], $this->state));
        $this->assertSame($before, $this->state);
    }

    public function test_prepared_acf_write_refuses_a_later_external_change_even_with_its_fresh_expected_snapshot(): void
    {
        $args = ['context' => 'post', 'id' => 43, 'field_key' => 'field_heading'];
        $read = $this->world->handle('wp_acf_get', $args, $this->state);
        $prepared = $this->world->handle('wp_acf_prepare', $args + ['expected' => $read['snapshots']['field_heading'],
            'operations' => [['op' => 'set', 'path' => [], 'value' => 'Requested']]], $this->state);
        $this->state['acf']['post'][43]['field_heading'] = 'External edit';
        $fresh = $this->world->handle('wp_acf_get', $args, $this->state);
        try {
            $this->world->handle('wp_acf_update', $args + ['expected' => $fresh['snapshots']['field_heading'], 'prepared' => $prepared['prepared']], $this->state);
            $this->fail('An old proposal must not bind to a new expected state.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('stale', $error->getMessage());
        }
        $this->assertSame('External edit', $this->state['acf']['post'][43]['field_heading']);
    }

    private function read(string $name, array $args): array
    {
        $read = app(SiteAgentToolbox::class)->read($this->site, $name, $args);
        $this->assertFalse($read['is_error'], $read['content']);

        return $read['ids'];
    }

    private function request(array $offer): SiteAgentRequest
    {
        $request = new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview']]);
        $request->setRelation('site', $this->site);

        return $request;
    }
}
