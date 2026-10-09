<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionApplier;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentPermissions;
use App\Services\SiteAgent\SiteAgentToolbox;
use App\Services\SiteAgent\SiteChangeApplier;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Category campaigns require a scoped live read, full consent, and native sealed undo. */
class SiteAgentCategorySalesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private array $answers = [];

    private array $calls = [];

    private array $selector = ['category_id' => 17, 'include_children' => true];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        $this->site = Site::factory()->create([
            'domain' => 'category-sale.example', 'mcp_enabled' => true, 'mcp_secret' => 'site-secret',
            'mcp_endpoint' => 'https://category-sale.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => array_map(fn (string $name): array => ['name' => $name], [
                'wc_category_sale_get', 'wc_category_sale_prepare', 'wc_category_sale_apply', 'wc_category_sale_revert',
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
        $this->answers['wc_category_sale_get'] = $this->record();
        $this->answers['wc_category_sale_prepare'] = $this->prepared();
        $this->answers['wc_category_sale_apply'] = ['changed' => true, 'campaign_id' => 'campaign-17', 'before' => $this->seal('before'), 'after' => $this->seal('after')];
        $this->answers['wc_category_sale_revert'] = ['changed' => true];
    }

    public function test_reads_hide_native_tokens_and_authorize_only_the_matching_category_scope(): void
    {
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_category_sale', $this->selector + ['expected' => $this->seal('forged')]);
        $this->assertFalse($read['is_error'], $read['content']);
        $this->assertSame(['category-sale:17:1'], $read['ids']);
        $this->assertSame(['wc_category_sale_get', $this->selector], $this->calls[0]);
        $this->assertStringContainsString('חולצה כחולה', $read['content']);
        $this->assertStringNotContainsString($this->seal('before')['token'], $read['content']);
        $this->assertStringNotContainsString('snapshot', $read['content']);

        foreach ([[], [17], [51], ['category-sale:18:1'], ['category-sale:17:0']] as $seen) {
            $this->assertArrayHasKey('error', $this->propose([], $seen));
        }
        $this->assertSame(['wc_category_sale_get'], array_column($this->calls, 0));
    }

    public function test_malformed_reads_never_grant_references_or_leak_native_tokens(): void
    {
        $this->answers['wc_category_sale_get'] = ['_wire_text' => '{"snapshot":{"token":"BROKEN-SECRET"}'];
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_category_sale', $this->selector);
        $this->assertTrue($read['is_error']);
        $this->assertSame([], $read['ids']);
        $this->assertStringNotContainsString('BROKEN-SECRET', $read['content']);
    }

    public function test_large_model_reads_report_a_summary_with_counts_instead_of_an_incomplete_product_list(): void
    {
        $rows = [];
        foreach (range(1, 160) as $index) {
            $row = $this->rows()[0];
            $row['id'] = $index + 100;
            $row['name'] = 'שם מוצר מתוך רשימת המבצע המלאה '.$index;
            $rows[] = $row;
        }
        $this->answers['wc_category_sale_get']['products'] = $rows;
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_category_sale', $this->selector);
        $this->assertFalse($read['is_error']);
        $this->assertSame(['category-sale:17:1'], $read['ids']);
        $data = json_decode($read['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(160, $data['product_count']);
        $this->assertArrayNotHasKey('products', $data);
        $this->assertArrayHasKey('notice', $data);
        $this->assertStringNotContainsString($this->seal('before')['token'], $read['content']);
    }

    public function test_proposal_rereads_live_state_and_shows_every_product_schedule_and_exclusion_without_writing(): void
    {
        $this->answers['wc_category_sale_get']['snapshot'] = $this->seal('fresh');
        $this->answers['wc_category_sale_prepare']['expected'] = $this->seal('fresh');
        $offer = $this->propose(['expected' => $this->seal('forged'), 'prepared' => $this->seal('forged')]);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame('category_sale', $offer['plan']['operation']);
        $this->assertSame($this->selector, $offer['plan']['arguments']);
        $this->assertSame($this->seal('fresh'), $offer['plan']['expected']);
        $this->assertSame($this->seal('prepared'), $offer['plan']['prepared']);
        foreach (['ביגוד', 'חולצה כחולה', '100.00', '80.00', 'חולצה אדומה — M', '150.00', '120.00', 'Asia/Jerusalem', '2030-06-02 10:45', '2030-06-04 22:30', 'מוצר ללא מחיר'] as $text) {
            $this->assertStringContainsString($text, $offer['preview']);
        }
        $this->assertSame(['wc_category_sale_get', 'wc_category_sale_prepare'], array_column($this->calls, 0));
        $this->assertSame($this->seal('fresh'), $this->calls[1][1]['expected']);
        $this->assertSame('20', $this->calls[1][1]['discount_value']);
        $this->assertStringNotContainsString($this->seal('prepared')['token'], $offer['preview']);
    }

    public function test_discount_and_schedule_values_are_validated_without_silently_coercing_bad_input(): void
    {
        foreach ([
            ['category_id' => 0], ['category_id' => true], ['include_children' => 'false'],
            ['discount_type' => 'other'], ['discount_value' => '0'], ['discount_value' => '-1'],
            ['discount_value' => '101'], ['discount_value' => '20 percent'], ['discount_value' => []],
            ['ends_at' => '2030-06-04'], ['starts_at' => '2030-02-30 10:30'],
            ['ends_at' => '2030-06-04T22:30:00Z'], ['replace_existing' => 'false'],
        ] as $bad) {
            $this->calls = [];
            $this->assertArrayHasKey('error', $this->propose($bad), json_encode($bad));
            $this->assertNotContains('wc_category_sale_prepare', array_column($this->calls, 0));
        }
    }

    public function test_existing_sale_replacement_requires_the_explicit_flag_to_reach_native_preparation(): void
    {
        $this->propose();
        $this->assertFalse(end($this->calls)[1]['replace_existing']);
        $this->answers['wc_category_sale_prepare']['notes'] = ['המבצע הקיים יוחלף לפי אישורך.'];
        $offer = $this->propose(['replace_existing' => true]);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertTrue(end($this->calls)[1]['replace_existing']);
        $this->assertStringContainsString('המבצע הקיים יוחלף', $offer['preview']);
    }

    public function test_mismatched_live_state_or_preparation_never_creates_an_offer(): void
    {
        foreach ([['snapshot' => []], ['category' => ['id' => 18, 'name' => 'Other category']]] as $corruption) {
            $this->answers['wc_category_sale_get'] = array_replace($this->record(), $corruption);
            $this->calls = [];
            $this->assertArrayHasKey('error', $this->propose());
            $this->assertNotContains('wc_category_sale_prepare', array_column($this->calls, 0));
        }
        $this->answers['wc_category_sale_get'] = $this->record();
        foreach ([['changed' => false], ['prepared' => []], ['expected' => $this->seal('other')], ['after' => []]] as $corruption) {
            $this->answers['wc_category_sale_prepare'] = array_replace($this->prepared(), $corruption);
            $this->assertArrayHasKey('error', $this->propose(), json_encode($corruption));
        }
        $this->assertNotContains('wc_category_sale_apply', array_column($this->calls, 0));
    }

    public function test_expired_sale_metadata_does_not_require_permission_to_replace_a_live_sale(): void
    {
        $this->answers['wc_category_sale_get']['products'][0]['sale_price'] = '90.00';
        $this->answers['wc_category_sale_get']['products'][0]['sale_to'] = now()->subDay()->timestamp;
        $this->answers['wc_category_sale_prepare']['before'] = $this->answers['wc_category_sale_get']['products'];
        $offer = $this->propose();
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertFalse($offer['plan']['category_sale_review']['replace_existing']);
        $this->assertStringContainsString('90.00', $offer['preview']);
        $this->assertStringContainsString('80.00', $offer['preview']);
    }

    public function test_preparation_cannot_omit_duplicate_or_change_the_price_of_a_product_in_the_live_selection(): void
    {
        $baseline = $this->prepared();
        $omitted = $baseline;
        array_pop($omitted['after']);
        $duplicate = $baseline;
        $duplicate['after'][1] = $duplicate['after'][0];
        $differentRegular = $baseline;
        $differentRegular['after'][0]['regular_price'] = '101.00';
        $differentBefore = $baseline;
        $differentBefore['before'][0]['sale_price'] = '99.00';
        foreach ([$omitted, $duplicate, $differentRegular, $differentBefore] as $prepared) {
            $this->answers['wc_category_sale_prepare'] = $prepared;
            $this->assertArrayHasKey('error', $this->propose());
        }
        $this->assertNotContains('wc_category_sale_apply', array_column($this->calls, 0));
    }

    public function test_declared_schedule_and_product_timestamps_must_match_the_requested_minutes_and_timezone(): void
    {
        foreach (['starts_at' => '2030-06-02 10:44', 'ends_at' => '2030-06-04 22:31', 'timezone' => 'UTC'] as $key => $value) {
            $this->answers['wc_category_sale_prepare'] = $this->prepared();
            $this->answers['wc_category_sale_prepare']['schedule'][$key] = $value;
            $this->assertArrayHasKey('error', $this->propose());
        }
        foreach (['sale_from', 'sale_to'] as $key) {
            $this->answers['wc_category_sale_prepare'] = $this->prepared();
            $this->answers['wc_category_sale_prepare']['after'][1][$key] += 60;
            $this->assertArrayHasKey('error', $this->propose(), $key.' must match the schedule actually shown to the customer.');
        }
        $this->assertNotContains('wc_category_sale_apply', array_column($this->calls, 0));
    }

    public function test_apply_and_undo_use_opposite_seals_and_the_same_category_selection(): void
    {
        $request = $this->request($this->propose());
        $applier = app(SiteActionApplier::class);
        $applied = $applier->apply($this->site, $request);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertSame(['wc_category_sale_apply', $this->selector + ['expected' => $this->seal('before'), 'prepared' => $this->seal('prepared')]], end($this->calls));
        $this->assertTrue($applier->reverts('category_sale'));
        $this->assertSame('category_sale', $applied['restore']['kind']);
        $this->assertTrue($applier->revert($this->site, $applied['restore'])['ok']);
        $this->assertSame('wc_category_sale_revert', end($this->calls)[0]);
        $this->assertSame($this->selector, array_intersect_key(end($this->calls)[1], $this->selector));
        $this->assertSame($this->seal('after'), end($this->calls)[1]['expected']);
        $this->assertSame($this->seal('before'), end($this->calls)[1]['restore']);
    }

    public function test_product_permissions_cover_proposing_applying_and_reverting_campaigns(): void
    {
        $request = $this->request($this->propose());
        $restore = ['kind' => 'category_sale', 'arguments' => $this->selector, 'campaign_id' => 'campaign-17', 'before' => $this->seal('before'), 'after' => $this->seal('after')];
        config(['siteagent.assistant.disabled_permissions' => ['products_update']]);
        $this->calls = [];
        $this->assertFalse(app(SiteAgentPermissions::class)->allowsTool('propose_category_sale'));
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        $this->assertFalse(app(SiteActionApplier::class)->revert($this->site, $restore)['ok']);
        $this->assertSame([], $this->calls);
    }

    public function test_older_sites_never_advertise_or_execute_unsupported_category_campaigns(): void
    {
        $request = $this->request($this->propose());
        $this->site->mcp_capabilities = ['tools' => [['name' => 'wc_product_get'], ['name' => 'wc_product_update']]];
        $this->calls = [];
        $this->assertNotContains('get_category_sale', array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name'));
        $this->assertNotContains('propose_category_sale', array_column(app(SiteActionProposer::class)->definitions($this->site), 'name'));
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        $this->assertSame([], $this->calls);
    }

    public function test_failure_and_incomplete_write_results_are_never_reported_as_success(): void
    {
        $request = $this->request($this->propose());
        $this->answers['wc_category_sale_apply'] = function (): array {
            throw new RuntimeException('Category membership changed since approval');
        };
        $failed = app(SiteActionApplier::class)->apply($this->site, $request);
        $this->assertFalse($failed['ok']);
        $this->assertNull($failed['restore']);
        foreach ([['changed' => false], ['changed' => true], ['changed' => true, 'before' => $this->seal('before'), 'after' => ['token' => 'invalid']]] as $result) {
            $this->answers['wc_category_sale_apply'] = $result;
            $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        }
    }

    public function test_large_campaigns_retain_the_complete_review_instead_of_silently_truncating_consent(): void
    {
        $prepared = $this->prepared();
        $prepared['before'] = $prepared['after'] = [];
        foreach (range(1, 90) as $index) {
            $row = $this->rows()[0];
            $row['id'] = $index + 100;
            $row['name'] = 'חולצה בקטלוג — דגם מספר '.$index;
            $prepared['before'][] = $row;
            $row['sale_price'] = '80.00';
            $row['sale_from'] = $this->prepared()['after'][0]['sale_from'];
            $row['sale_to'] = $this->prepared()['after'][0]['sale_to'];
            $prepared['after'][] = $row;
        }
        $this->answers['wc_category_sale_get']['products'] = $prepared['before'];
        $this->answers['wc_category_sale_prepare'] = $prepared;
        $offer = $this->propose();
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertLessThanOrEqual(3500, mb_strlen($offer['preview']));
        $this->assertCount(90, $offer['plan']['category_sale_review']['products']);
        $this->assertSame(190, $offer['plan']['category_sale_review']['products'][89]['id']);
        $this->assertSame('80.00', $offer['plan']['category_sale_review']['products'][89]['after']['sale_price']);
        $this->assertSame($prepared['excluded'], $offer['plan']['category_sale_review']['excluded']);
        $this->assertNotContains('wc_category_sale_apply', array_column($this->calls, 0));
    }

    public function test_single_product_sales_preserve_exact_minutes_only_on_a_supported_plugin(): void
    {
        $this->site->mcp_capabilities = ['server' => ['version' => '1.11.0'], 'tools' => [
            ['name' => 'wc_product_get'], ['name' => 'wc_product_update'],
        ]];
        $this->answers['wc_product_get'] = ['id' => 51, 'name' => 'חולצה כחולה', 'regular_price' => '100.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null];
        $input = ['product_id' => 51, 'sale_price' => '80.00', 'sale_from' => '2030-06-02 10:45', 'sale_to' => '2030-06-04 22:30'];
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_product_update', $input, [51]);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame('2030-06-02 10:45', $offer['plan']['fields']['sale_from']);
        $this->assertSame('2030-06-04 22:30', $offer['plan']['fields']['sale_to']);
        $this->assertStringContainsString('2030-06-02 10:45', $offer['preview']);
        $this->assertStringContainsString('2030-06-04 22:30', $offer['preview']);

        $capabilities = $this->site->mcp_capabilities;
        $capabilities['server']['version'] = '1.10.0';
        $this->site->mcp_capabilities = $capabilities;
        $this->calls = [];
        $this->assertArrayHasKey('error', app(SiteActionProposer::class)->propose($this->site, 'propose_product_update', $input, [51]));
        $this->assertSame([], $this->calls, 'An older plugin must not silently discard the requested minutes.');
    }

    public function test_single_product_precise_dates_are_not_applied_or_restored_after_a_plugin_downgrade(): void
    {
        $this->site->mcp_capabilities = ['server' => ['version' => '1.10.0'], 'tools' => [
            ['name' => 'wc_product_get'], ['name' => 'wc_product_update'],
        ]];
        $fields = ['sale_price' => '80.00', 'sale_from' => '2030-06-02 10:45', 'sale_to' => '2030-06-04 22:30'];
        $request = new SiteAgentRequest([
            'operation' => SiteAgentRequest::OP_PRODUCT,
            'plan' => ['operation' => SiteAgentRequest::OP_PRODUCT, 'product_id' => 51, 'fields' => $fields,
                'current' => ['sale_price' => '', 'sale_from' => null, 'sale_to' => null]],
            'restore' => ['kind' => 'product', 'product_id' => 51, 'fields' => $fields,
                'after' => ['sale_price' => '', 'sale_from' => null, 'sale_to' => null]],
        ]);
        $request->setRelation('site', $this->site);
        $apply = app(SiteChangeApplier::class)->apply($request);
        $this->assertFalse($apply['ok']);
        $this->assertStringContainsString('1.11.0', $apply['reason']);
        $revert = app(SiteChangeApplier::class)->revert($request);
        $this->assertFalse($revert['ok']);
        $this->assertStringContainsString('1.11.0', $revert['reason']);
        $this->assertSame([], $this->calls, 'Downgrading the plugin must not silently widen the approved sale dates.');
    }

    private function propose(array $input = [], ?array $seen = null): array
    {
        return app(SiteActionProposer::class)->propose($this->site, 'propose_category_sale', $input + $this->selector + [
            'discount_type' => 'percent', 'discount_value' => '20', 'starts_at' => '2030-06-02 10:45',
            'ends_at' => '2030-06-04 22:30', 'replace_existing' => false,
        ], $seen ?? ['category-sale:17:1']);
    }

    private function request(array $offer): SiteAgentRequest
    {
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));

        return new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview']]);
    }

    private function seal(string $tag): array
    {
        return ['version' => hash('sha256', $tag), 'token' => base64_encode('opaque-category-'.$tag)];
    }

    private function rows(): array
    {
        return [
            ['id' => 51, 'parent_id' => 0, 'name' => 'חולצה כחולה', 'type' => 'simple', 'regular_price' => '100.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null],
            ['id' => 52, 'parent_id' => 50, 'name' => 'חולצה אדומה — M', 'type' => 'variation', 'regular_price' => '150.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null],
        ];
    }

    private function record(): array
    {
        return ['category' => ['id' => 17, 'name' => 'ביגוד'], 'include_children' => true, 'products' => $this->rows(),
            'excluded' => [['id' => 53, 'name' => 'מוצר ללא מחיר', 'reason' => 'missing_regular_price']],
            'timezone' => 'Asia/Jerusalem', 'currency' => 'ILS', 'snapshot' => $this->seal('before')];
    }

    private function prepared(): array
    {
        $before = $this->rows();
        $after = $before;
        $after[0]['sale_price'] = '80.00';
        $after[1]['sale_price'] = '120.00';
        foreach ($after as &$row) {
            $row['sale_from'] = (new \DateTimeImmutable('2030-06-02 10:45', new \DateTimeZone('Asia/Jerusalem')))->getTimestamp();
            $row['sale_to'] = (new \DateTimeImmutable('2030-06-04 22:30', new \DateTimeZone('Asia/Jerusalem')))->getTimestamp() - 1;
        }

        return ['category' => ['id' => 17, 'name' => 'ביגוד'], 'include_children' => true, 'before' => $before, 'after' => $after,
            'expected' => $this->seal('before'), 'prepared' => $this->seal('prepared'),
            'schedule' => ['starts_at' => '2030-06-02 10:45', 'ends_at' => '2030-06-04 22:30', 'timezone' => 'Asia/Jerusalem'],
            'excluded' => $this->record()['excluded'], 'notes' => [], 'changed' => true, 'currency' => 'ILS', 'timezone' => 'Asia/Jerusalem'];
    }
}
