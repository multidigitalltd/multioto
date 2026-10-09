<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteChangeApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentVirtualProductsTest extends TestCase
{
    use RefreshDatabase;

    private array $calls = [];

    private array $product;

    private bool $flipVirtualOnPublish = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.assistant.disabled_permissions' => '']);
        $this->product = ['id' => 7, 'type' => 'simple', 'virtual' => false, 'name' => 'דוגמה', 'regular_price' => '150.00', 'sale_price' => '', 'status' => 'draft'];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $arguments = []): array {
            $this->calls[] = [$tool, $arguments];
            if ($tool === 'wc_product_get') {
                return $this->product;
            }
            if ($tool === 'wc_product_create') {
                $this->product = [...$this->product, ...$arguments];

                return $this->product;
            }
            if ($tool === 'wc_product_update') {
                $previous = $this->product;
                $this->product = [...$this->product, ...array_diff_key($arguments, ['product_id' => true])];
                if ($this->flipVirtualOnPublish && ($arguments['status'] ?? null) === 'publish') {
                    $this->product['virtual'] = false;
                }

                return ['ok' => true, 'previous' => $previous];
            }
            $this->fail('Unexpected tool '.$tool);
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result): string => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);
    }

    private function site(string $version = '1.12.0'): Site
    {
        return Site::factory()->create(['mcp_capabilities' => ['server' => ['version' => $version]]]);
    }

    private function proposal(Site $site, array $input, bool $create = false): array
    {
        return app(SiteActionProposer::class)->propose($site, $create ? 'propose_product_create' : 'propose_product_update', $input, [7]);
    }

    private function request(Site $site, array $plan): SiteAgentRequest
    {
        $request = new SiteAgentRequest(['operation' => $plan['operation'], 'plan' => $plan]);
        $request->setRelation('site', $site);

        return $request;
    }

    private function undo(Site $site, array $restore): array
    {
        $request = new SiteAgentRequest(['restore' => $restore]);
        $request->setRelation('site', $site);

        return app(SiteChangeApplier::class)->revert($request);
    }

    public static function booleans(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('booleans')]
    public function test_create_preserves_boolean_in_initial_draft_and_verifies_before_publishing(bool $virtual): void
    {
        $site = $this->site();
        $offer = $this->proposal($site, ['name' => 'דוגמה', 'regular_price' => '150', 'virtual' => $virtual, 'publish' => true], true);
        $this->assertSame($virtual, $offer['plan']['fields']['virtual']);
        $this->assertStringContainsString($virtual ? 'וירטואלי — ללא משלוח' : 'פיזי — משלוח לפי הגדרות החנות', $offer['preview']);
        $this->assertSame([], $this->calls);

        $result = app(SiteChangeApplier::class)->apply($this->request($site, $offer['plan']));

        $this->assertTrue($result['ok']);
        $this->assertSame(['wc_product_create', 'wc_product_get', 'wc_product_update', 'wc_product_get'], array_column($this->calls, 0));
        $this->assertSame($virtual, $this->calls[0][1]['virtual']);
        $this->assertSame(['product_id' => 7, 'status' => 'publish'], $this->calls[2][1]);
        $this->assertSame($virtual, $this->product['virtual']);
        $this->assertSame(7, $result['created_id']);
        $this->assertStringContainsString('נוצר ופורסם', $result['done']);
    }

    public function test_new_product_without_virtual_is_explicitly_previewed_as_physical(): void
    {
        $offer = $this->proposal($this->site(), ['name' => 'מוצר פיזי'], true);
        $this->assertFalse($offer['plan']['fields']['virtual']);
        $this->assertStringContainsString('סוג מוצר: פיזי', $offer['preview']);
        $legacy = $this->proposal($this->site('1.11.0'), ['name' => 'מוצר פיזי'], true);
        $this->assertArrayNotHasKey('virtual', $legacy['plan']['fields']);
        $this->assertStringContainsString('סוג מוצר: פיזי', $legacy['preview']);
    }

    public static function invalidValues(): array
    {
        return [['true'], ['false'], [1], [0], [null], [[]]];
    }

    #[DataProvider('invalidValues')]
    public function test_boolean_values_are_never_coerced_for_create_or_update(mixed $value): void
    {
        $site = $this->site();
        $this->assertArrayHasKey('error', $this->proposal($site, ['product_id' => 7, 'virtual' => $value]));
        $this->assertArrayHasKey('error', $this->proposal($site, ['name' => 'מוצר', 'virtual' => $value], true));
        $this->assertSame([], $this->calls);
    }

    #[DataProvider('booleans')]
    public function test_old_plugin_refuses_explicit_flags_at_proposal_and_execution(bool $virtual): void
    {
        $site = $this->site('1.11.0');
        $this->assertStringContainsString('1.12.0', $this->proposal($site, ['product_id' => 7, 'virtual' => $virtual])['error']);
        $this->assertStringContainsString('1.12.0', $this->proposal($site, ['name' => 'מוצר', 'virtual' => $virtual], true)['error']);
        foreach ([SiteAgentRequest::OP_PRODUCT, SiteAgentRequest::OP_PRODUCT_CREATE] as $operation) {
            $result = app(SiteChangeApplier::class)->apply($this->request($site, [
                'operation' => $operation, 'product_id' => 7,
                'fields' => ['name' => 'מוצר', 'virtual' => $virtual], 'current' => ['virtual' => ! $virtual],
            ]));
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('1.12.0', $result['message']);
        }
        $this->assertSame([], $this->calls);
    }

    #[DataProvider('booleans')]
    public function test_update_and_undo_change_only_virtual_and_preserve_prices(bool $virtual): void
    {
        $site = $this->site();
        $this->product['virtual'] = ! $virtual;
        $offer = $this->proposal($site, ['product_id' => 7, 'virtual' => $virtual]);
        $this->assertSame(['virtual' => $virtual], $offer['plan']['fields']);
        $this->assertSame(['virtual' => ! $virtual], $offer['plan']['current']);
        $this->assertStringContainsString('וירטואלי — ללא משלוח', $offer['preview']);
        $this->assertStringContainsString('פיזי — משלוח לפי הגדרות החנות', $offer['preview']);
        $this->product['regular_price'] = '175.00';
        $result = app(SiteChangeApplier::class)->apply($this->request($site, $offer['plan']));
        $this->assertTrue($result['ok']);
        $this->assertSame(['virtual' => ! $virtual], $result['restore']['fields']);
        $this->assertSame(['virtual' => $virtual], $result['restore']['after']);
        $this->assertSame('175.00', $this->product['regular_price']);
        $undo = $this->undo($site, $result['restore']);
        $this->assertTrue($undo['ok']);
        $this->assertSame(! $virtual, $this->product['virtual']);
        $this->assertSame('175.00', $this->product['regular_price']);
        $writes = array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === 'wc_product_update'));
        $this->assertSame(['product_id' => 7, 'virtual' => $virtual], $writes[0][1]);
        $this->assertSame(['product_id' => 7, 'virtual' => ! $virtual], $writes[1][1]);
    }

    public static function unsafeProducts(): array
    {
        return [['virtual', null], ['virtual', 'false'], ['virtual', 0], ['type', 'variable'], ['type', 'external'], ['type', 'grouped'], ['type', 'subscription'], ['id', 8]];
    }

    #[DataProvider('unsafeProducts')]
    public function test_missing_boolean_or_unsupported_product_refuses_before_any_write(string $key, mixed $value): void
    {
        $site = $this->site();
        $this->product[$key] = $value;
        $this->assertArrayHasKey('error', $this->proposal($site, ['product_id' => 7, 'virtual' => false]));
        $result = app(SiteChangeApplier::class)->apply($this->request($site, [
            'operation' => SiteAgentRequest::OP_PRODUCT, 'product_id' => 7,
            'fields' => ['virtual' => true], 'current' => ['virtual' => false],
        ]));
        $this->assertFalse($result['ok']);
        $this->assertNotContains('wc_product_update', array_column($this->calls, 0));
    }

    public function test_variations_can_change_and_no_op_is_refused(): void
    {
        $site = $this->site();
        $this->product['type'] = 'variation';
        $this->assertArrayHasKey('plan', $this->proposal($site, ['product_id' => 7, 'virtual' => true]));
        $this->assertArrayHasKey('error', $this->proposal($site, ['product_id' => 7, 'virtual' => false]));
    }

    public function test_virtual_drift_stops_apply_and_undo_and_legacy_plugin_cannot_undo(): void
    {
        $site = $this->site();
        $offer = $this->proposal($site, ['product_id' => 7, 'virtual' => true]);
        $this->product['virtual'] = true;
        $this->assertSame(SiteChangeApplier::STALE, app(SiteChangeApplier::class)->apply($this->request($site, $offer['plan']))['reason']);
        $restore = ['kind' => 'product', 'product_id' => 7, 'fields' => ['virtual' => false], 'after' => ['virtual' => true]];
        $this->product['virtual'] = false;
        $this->assertSame(SiteChangeApplier::STALE, $this->undo($site, $restore)['reason']);
        $this->assertStringContainsString('1.12.0', $this->undo($this->site('1.11.0'), $restore)['message']);
        unset($this->product['virtual']);
        $this->assertFalse($this->undo($site, $restore)['ok']);
        $this->assertNotContains('wc_product_update', array_column($this->calls, 0));
    }

    public function test_creation_with_unverified_virtual_flag_keeps_existing_draft_without_publishing_or_false_success(): void
    {
        $site = $this->site();
        $offer = $this->proposal($site, ['name' => 'דוגמה', 'regular_price' => '150', 'virtual' => true, 'publish' => true], true);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->with($site, 'wc_product_create', $offer['plan']['fields'], Mockery::any())->once()->andReturn(['id' => 7]);
        $mcp->shouldReceive('callTool')->with($site, 'wc_product_get', ['product_id' => 7], Mockery::any())->once()->andReturn($this->product);
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result): string => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);

        $result = app(SiteChangeApplier::class)->apply($this->request($site, $offer['plan']));

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['partial']);
        $this->assertSame(7, $result['created_id']);
        $this->assertNull($result['restore']);
        $this->assertStringContainsString('לא ביצעתי את שלב הפרסום', $result['done']);
        $this->assertStringContainsString('אין ליצור את המוצר שוב', $result['done']);
    }

    public function test_a_publication_hook_changing_virtual_is_reported_as_partial_not_as_verified_creation(): void
    {
        $site = $this->site();
        $this->flipVirtualOnPublish = true;
        $offer = $this->proposal($site, ['name' => 'דוגמה', 'regular_price' => '150', 'virtual' => true, 'publish' => true], true);

        $result = app(SiteChangeApplier::class)->apply($this->request($site, $offer['plan']));

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['partial']);
        $this->assertStringContainsString('ייתכן שהוא כבר פורסם', $result['done']);
        $this->assertSame(7, $result['created_id']);
        $this->assertStringContainsString('אין ליצור את המוצר שוב', $result['done']);
        $this->assertSame('publish', $this->product['status']);
        $this->assertFalse($this->product['virtual']);
        $this->assertCount(1, array_filter($this->calls, fn (array $call): bool => $call[0] === 'wc_product_create'));
    }
}
