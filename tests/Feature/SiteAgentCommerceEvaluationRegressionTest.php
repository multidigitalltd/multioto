<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\Evaluation\EvaluationMcpClient;
use App\Services\SiteAgent\Evaluation\EvaluationWorld;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentToolbox;
use App\Services\SiteAgent\SiteChangeApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regressions from the real model report; these test execution, not model understanding. */
class SiteAgentCommerceEvaluationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationWorld $world;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['https://evaluation.example' => Http::response('Healthy', 200)]);
        config(['siteagent.assistant.disabled_permissions' => []]);
        $this->world = new EvaluationWorld;
        $this->site = Site::factory()->create([
            'domain' => 'evaluation.example',
            'mcp_endpoint' => 'https://evaluation.example/wp-json/multioto/v1/mcp',
            'mcp_enabled' => true,
            'mcp_capabilities' => [
                'server' => ['version' => '1.12.0'],
                'tools' => array_map(fn (string $name): array => ['name' => $name], $this->world->supportedTools()),
            ],
        ]);
        $this->app->instance(McpClient::class, new EvaluationMcpClient($this->world, $this->site->id));
    }

    public function test_explicit_product_text_reaches_draft_unchanged_including_final_punctuation(): void
    {
        $text = "עט כחול עם גוף מתכתי.\nנוח לכתיבה!";
        $offer = $this->propose('propose_product_create', [
            'name' => 'עט מתכת', 'regular_price' => '25', 'virtual' => false,
            'short_description' => $text, 'publish' => false,
        ]);
        $this->assertArrayNotHasKey(40000, $this->world->state['products']);
        $this->assertSame($text, $offer['plan']['fields']['short_description']);
        $result = app(SiteChangeApplier::class)->apply($this->request($offer));
        $this->assertTrue($result['ok']);
        $this->assertSame($text, $this->world->state['products'][40000]['short_description']);
        $this->assertSame('draft', $this->world->state['products'][40000]['status']);
        $this->assertSame('25.00', $this->world->state['products'][40000]['regular_price']);
        $this->assertFalse($this->world->state['products'][40000]['virtual']);
    }

    public function test_single_product_sale_needs_no_date_and_can_end_without_changing_regular_price(): void
    {
        $before = $this->world->state['products'][7];
        $seen = $this->readProduct(7);
        $offer = $this->propose('propose_product_update', ['product_id' => 7, 'sale_price' => '80'], $seen);
        $this->assertSame(['sale_price' => '80'], $offer['plan']['fields']);
        $this->assertSame($before, $this->world->state['products'][7]);
        $result = app(SiteChangeApplier::class)->apply($this->request($offer));
        $this->assertTrue($result['ok']);
        $this->assertSame('80.00', $this->world->state['products'][7]['sale_price']);
        $this->assertSame('', $this->world->state['products'][7]['sale_from']);
        $this->assertSame('', $this->world->state['products'][7]['sale_to']);

        $offer = $this->propose('propose_product_update', ['product_id' => 7, 'sale_price' => ''], $this->readProduct(7));
        $this->assertTrue(app(SiteChangeApplier::class)->apply($this->request($offer))['ok']);
        $this->assertSame('100.00', $this->world->state['products'][7]['regular_price']);
        $this->assertSame('', $this->world->state['products'][7]['sale_price']);
    }

    public function test_product_id_can_be_read_then_trashed_and_restored_without_searching_it_as_text(): void
    {
        $before = $this->world->state['products'][33852];
        $seen = $this->readProduct(33852);
        $offer = $this->propose('propose_product_trash', ['product_id' => 33852], $seen);
        $this->assertSame($before, $this->world->state['products'][33852]);
        $request = $this->request($offer);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($result['ok']);
        $this->assertSame('trash', $this->world->state['products'][33852]['status']);
        $request->restore = $result['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame($before, $this->world->state['products'][33852]);
        $this->assertNotContains('wc_product_search', array_column($this->world->calls, 'tool'));
    }

    public function test_invalid_sale_does_not_raise_regular_price_to_make_it_valid(): void
    {
        $before = $this->world->state;
        $seen = $this->readProduct(7);
        foreach (['100', '110'] as $sale) {
            $offer = $this->propose('propose_product_update', ['product_id' => 7, 'sale_price' => $sale], $seen);
            $this->assertArrayHasKey('error', $offer);
            $this->assertStringContainsString('נמוך מהמחיר הרגיל', $offer['error']);
        }
        $this->assertSame($before, $this->world->state);
        $this->assertNotContains('wc_product_update', array_column($this->world->calls, 'tool'));
    }

    public function test_inverted_sale_dates_are_refused_before_a_preview_and_before_a_forged_plan_can_write(): void
    {
        $fields = ['sale_price' => '80', 'sale_from' => '2032-03-10 18:00', 'sale_to' => '2032-03-09 18:00'];
        $before = $this->world->state;
        $offer = $this->propose('propose_product_update', ['product_id' => 7, ...$fields], $this->readProduct(7));
        $this->assertArrayHasKey('error', $offer);
        $this->assertStringContainsString('אחרי תחילתו', $offer['error']);
        $request = $this->request(['plan' => ['operation' => SiteAgentRequest::OP_PRODUCT, 'product_id' => 7, 'fields' => $fields]]);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertFalse($result['ok']);
        $this->assertSame($before, $this->world->state);
        $this->assertNotContains('wc_product_update', array_column($this->world->calls, 'tool'));
    }

    public function test_end_date_only_checks_the_live_start_and_stops_if_it_moves_past_the_approved_end(): void
    {
        $this->world->state['products'][7]['sale_from'] = '2032-03-10T18:00:00+02:00';
        $this->world->state['products'][7]['sale_to'] = '2032-03-14T18:00:00+02:00';
        $this->world->state['products'][7]['sale_price'] = '80.00';
        $seen = $this->readProduct(7);
        $invalid = $this->propose('propose_product_update', ['product_id' => 7, 'sale_to' => '2032-03-09 18:00'], $seen);
        $this->assertArrayHasKey('error', $invalid);
        $offer = $this->propose('propose_product_update', ['product_id' => 7, 'sale_to' => '2032-03-12 18:00'], $seen);
        $this->assertArrayHasKey('plan', $offer);
        $this->world->state['products'][7]['sale_from'] = '2032-03-13T18:00:00+02:00';
        $before = $this->world->state;
        $result = app(SiteChangeApplier::class)->apply($this->request($offer));
        $this->assertFalse($result['ok']);
        $this->assertSame($before, $this->world->state);
        $this->assertNotContains('wc_product_update', array_column($this->world->calls, 'tool'));
    }

    public function test_approved_sale_uses_the_previewed_timezone_and_leaves_an_open_end_open(): void
    {
        $offer = $this->propose('propose_product_update', [
            'product_id' => 7, 'sale_price' => '80', 'sale_from' => '2032-03-10 18:00',
        ], $this->readProduct(7));
        $this->assertSame('Asia/Jerusalem', $offer['plan']['sale_timezone']);
        $this->world->state['products'][7]['timezone'] = 'UTC';
        $this->assertFalse(app(SiteChangeApplier::class)->apply($this->request($offer))['ok']);
        $this->assertNotContains('wc_product_update', array_column($this->world->calls, 'tool'));
        $this->world->state['products'][7]['timezone'] = 'Asia/Jerusalem';
        $this->assertTrue(app(SiteChangeApplier::class)->apply($this->request($offer))['ok']);
        $this->assertSame('2032-03-10 18:00', $this->world->state['products'][7]['sale_from']);
        $this->assertSame('', $this->world->state['products'][7]['sale_to']);
    }

    private function readProduct(int $id): array
    {
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_product', ['product_id' => $id]);
        $this->assertFalse($read['is_error']);
        $this->assertContains($id, $read['ids']);

        return $read['ids'];
    }

    private function propose(string $tool, array $args, array $seen = []): array
    {
        return app(SiteActionProposer::class)->propose($this->site, $tool, $args, $seen);
    }

    private function request(array $offer): SiteAgentRequest
    {
        $this->assertArrayHasKey('plan', $offer, json_encode($offer, JSON_UNESCAPED_UNICODE));
        $request = new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan']]);
        $request->setRelation('site', $this->site);

        return $request;
    }
}
