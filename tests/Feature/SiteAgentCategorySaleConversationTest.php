<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Agent\McpError;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangeApplier;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Real WhatsApp conversation and persistence; only AI and WooCommerce boundaries are mocked. */
class SiteAgentCategorySaleConversationTest extends TestCase
{
    use RefreshDatabase;

    private SiteAgentSubscriber $subscriber;

    private array $remote = [];

    private array $calls = [];

    private array $toolResults = [];

    private array $modelInput = [];

    private Closure $nextTurn;

    private int $messageNumber = 0;

    private array $selector = ['category_id' => 17, 'include_children' => true];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => [], 'siteagent.assistant.history_messages' => 4,
            'siteagent.confirmation_minutes' => 30, 'siteagent.undo_minutes' => 1440,
        ]);
        Cache::flush();
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'category-conversation.example',
            'mcp_enabled' => true, 'mcp_secret' => 'test-site-secret',
            'mcp_endpoint' => 'https://category-conversation.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => array_map(fn (string $tool): array => ['name' => $tool], [
                'wc_category_sale_get', 'wc_category_sale_prepare', 'wc_category_sale_apply', 'wc_category_sale_revert',
            ])],
        ]);
        $this->subscriber = SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id,
            'site_id' => $site->id, 'verified_at' => now(),
        ]);
        $this->resetRemote();
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->assertSame($this->subscriber->site_id, $site->id);
            $this->calls[] = [$tool, $args];

            return $this->remoteCall($tool, $args);
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $this->nextTurn = fn (): string => 'אין כרגע הצעה לאישור.';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('converse')->andReturnUsing(function (string $system, string $prompt, array $tools, callable $handler): string {
            $this->modelInput = [$system, $prompt, $tools];

            return ($this->nextTurn)(function (string $name, array $args) use ($handler): array {
                $result = $handler($name, $args);
                $this->toolResults[] = [$name, $result];

                return $result;
            });
        });
        $this->app->instance(ClaudeClient::class, $ai);
    }

    public function test_category_campaign_requires_consent_and_round_trips_once_through_confirmation_and_undo(): void
    {
        $request = $this->offer();
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $this->assertSame('category_sale', $request->operation);
        foreach (['ביגוד', 'חולצה כחולה', 'חולצה אדומה — M', 'Asia/Jerusalem', '2030-06-02 10:45', '2030-06-04 22:30'] as $text) {
            $this->assertStringContainsString($text, $request->preview);
        }
        $this->assertSame($this->remote['before'], $this->remote['current']);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame(['wc_category_sale_get', 'wc_category_sale_get', 'wc_category_sale_prepare'], array_column($this->calls, 0));

        $reply = $this->talk('כן');

        $this->assertStringContainsString('בוצע', $reply);
        $this->assertStringContainsString('בטל', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNotNull($request->applied_at);
        $this->assertSame($this->remote['after'], $this->remote['current']);
        $this->assertSame('category_sale', $request->restore['kind']);
        $this->assertSame($this->seal('before'), $request->restore['before']);
        $this->assertSame($this->seal('after'), $request->restore['after']);
        $this->assertSame(['wc_category_sale_apply', $this->selector + ['expected' => $this->seal('before'), 'prepared' => $this->seal('prepared')]], end($this->calls));
        $this->talk('כן');
        $this->assertSame(1, $this->remote['writes']);

        $reply = $this->talk('בטל');

        $this->assertStringContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertNotNull($request->reverted_at);
        $this->assertSame($this->remote['before'], $this->remote['current']);
        $this->assertSame('wc_category_sale_revert', end($this->calls)[0]);
        $this->assertSame($this->seal('after'), end($this->calls)[1]['expected']);
        $this->assertSame($this->seal('before'), end($this->calls)[1]['restore']);
        $this->assertSame(2, $this->remote['writes']);
        $this->talk('בטל');
        $this->assertSame(2, $this->remote['writes']);
    }

    public function test_declining_a_category_campaign_never_changes_any_product(): void
    {
        $request = $this->offer();
        $reply = $this->talk('לא');
        $this->assertStringContainsString('בוטל', $reply);
        $this->assertSame(SiteAgentRequest::CANCELED, $request->refresh()->state);
        $this->talk('כן');
        $this->assertSame($this->remote['before'], $this->remote['current']);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertNotContains('wc_category_sale_apply', array_column($this->calls, 0));
        $this->assertNotContains('wc_category_sale_revert', array_column($this->calls, 0));
    }

    public function test_disabling_products_after_the_offer_blocks_confirmation_without_remote_writes(): void
    {
        $request = $this->offer();
        config(['siteagent.assistant.disabled_permissions' => ['products_update']]);
        $this->calls = [];
        $this->talk('כן');
        $this->assertNotSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame([], $this->calls);
    }

    public function test_category_membership_or_prices_changed_after_approval_are_not_overwritten_or_retried(): void
    {
        $request = $this->offer();
        $this->remote['current'][0]['regular_price'] = '200.00';
        $this->remote['current_snapshot'] = $this->seal('dashboard');
        $this->calls = [];
        $reply = $this->talk('כן');
        $this->assertSame(SiteAgentRequest::FAILED, $request->refresh()->state);
        $this->assertNull($request->restore);
        $this->assertNull($request->applied_at);
        $this->assertStringNotContainsString('✅ בוצע', $reply);
        $this->assertSame('200.00', $this->remote['current'][0]['regular_price']);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame(['wc_category_sale_apply'], array_column($this->calls, 0));
        $this->talk('כן');
        $this->assertSame(['wc_category_sale_apply'], array_column($this->calls, 0));
        $this->assertSame(1, SiteAgentRequest::count());
    }

    public function test_undo_preserves_later_dashboard_changes_and_keeps_the_request_applied(): void
    {
        $request = $this->offer();
        $this->talk('כן');
        $this->remote['current'][0]['sale_price'] = '77.00';
        $this->remote['current_snapshot'] = $this->seal('later-editor');
        $reply = $this->talk('בטל');
        $this->assertStringNotContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNull($request->reverted_at);
        $this->assertSame('77.00', $this->remote['current'][0]['sale_price']);
        $this->assertSame(1, $this->remote['writes']);
        $this->assertSame($this->seal('after'), end($this->calls)[1]['expected']);
    }

    public function test_seals_never_enter_model_results_or_follow_up_context_and_a_follow_up_needs_a_live_read(): void
    {
        $this->offer();
        $this->talk('כן');
        $this->calls = [];
        $this->nextTurn = function (callable $tool): string {
            foreach (['before', 'prepared', 'after'] as $tag) {
                $this->assertStringNotContainsString($this->seal($tag)['token'], json_encode($this->modelInput));
                $this->assertStringNotContainsString($this->seal($tag)['token'], json_encode($this->toolResults));
            }
            $this->assertStringContainsString('category_sale', $this->modelInput[1]);
            $this->assertTrue($tool('propose_category_sale', $this->input())['is_error']);
            $this->assertSame([], $this->calls);
            $this->assertFalse($tool('get_category_sale', $this->selector)['is_error']);

            return 'בדקתי את המבצע הקיים.';
        };
        $this->talk('מה עשית בקטגוריה קודם?');
        $this->assertSame(['wc_category_sale_get'], array_column($this->calls, 0));
        $this->assertSame(1, $this->remote['writes']);
    }

    private function resetRemote(): void
    {
        $before = [
            ['id' => 51, 'parent_id' => 0, 'name' => 'חולצה כחולה', 'type' => 'simple', 'regular_price' => '100.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null],
            ['id' => 52, 'parent_id' => 50, 'name' => 'חולצה אדומה — M', 'type' => 'variation', 'regular_price' => '150.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null],
        ];
        $after = $before;
        $after[0]['sale_price'] = '80.00';
        $after[1]['sale_price'] = '120.00';
        foreach ($after as &$row) {
            $row['sale_from'] = (new \DateTimeImmutable('2030-06-02 10:45', new \DateTimeZone('Asia/Jerusalem')))->getTimestamp();
            $row['sale_to'] = (new \DateTimeImmutable('2030-06-04 22:30', new \DateTimeZone('Asia/Jerusalem')))->getTimestamp() - 1;
        }
        $this->remote = ['before' => $before, 'after' => $after, 'current' => $before,
            'current_snapshot' => $this->seal('before'), 'writes' => 0];
    }

    private function remoteCall(string $tool, array $args): array
    {
        $this->assertSame($this->selector, array_intersect_key($args, $this->selector));
        $context = ['category' => ['id' => 17, 'name' => 'ביגוד'], 'include_children' => true, 'timezone' => 'Asia/Jerusalem', 'currency' => 'ILS', 'excluded' => []];
        if ($tool === 'wc_category_sale_get') {
            return $context + ['products' => $this->remote['current'], 'snapshot' => $this->remote['current_snapshot']];
        }
        if ($tool === 'wc_category_sale_prepare') {
            $this->assertSame($this->remote['current_snapshot'], $args['expected']);

            return $context + ['before' => $this->remote['before'], 'after' => $this->remote['after'], 'changed' => true,
                'expected' => $args['expected'], 'prepared' => $this->seal('prepared'), 'notes' => [],
                'schedule' => ['starts_at' => '2030-06-02 10:45', 'ends_at' => '2030-06-04 22:30', 'timezone' => 'Asia/Jerusalem']];
        }
        if (! in_array($tool, ['wc_category_sale_apply', 'wc_category_sale_revert'], true)) {
            throw new RuntimeException('Unexpected remote tool: '.$tool);
        }
        if (($args['expected'] ?? null) !== $this->remote['current_snapshot']) {
            throw new McpError(SiteChangeApplier::STALE);
        }
        $before = $this->remote['current_snapshot'];
        if ($tool === 'wc_category_sale_apply') {
            $this->assertSame($this->seal('prepared'), $args['prepared']);
            $this->assertArrayNotHasKey('discount_value', $args);
            $this->remote['current'] = $this->remote['after'];
            $this->remote['current_snapshot'] = $this->seal('after');
        } else {
            $this->assertSame($this->seal('before'), $args['restore']);
            $this->remote['current'] = $this->remote['before'];
            $this->remote['current_snapshot'] = $this->seal('before');
        }
        $this->remote['writes']++;

        return ['changed' => true, 'before' => $before, 'after' => $this->remote['current_snapshot'], 'campaign_id' => 'campaign-17'];
    }

    private function offer(): SiteAgentRequest
    {
        $this->nextTurn = function (callable $tool): string {
            $this->assertContains('get_category_sale', array_column($this->modelInput[2], 'name'));
            $this->assertContains('propose_category_sale', array_column($this->modelInput[2], 'name'));
            $read = $tool('get_category_sale', $this->selector);
            $this->assertFalse($read['is_error'], $read['content']);
            $proposal = $tool('propose_category_sale', $this->input());
            $this->assertFalse($proposal['is_error'] ?? false, $proposal['content']);

            return 'This generated answer must not replace the real preview.';
        };
        $reply = $this->talk('צור מבצע 20 אחוז לכל קטגוריית ביגוד בשעות שביקשתי');
        $request = SiteAgentRequest::latest('id')->firstOrFail();
        $this->assertStringContainsString($request->preview, $reply);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame($this->subscriber->id, $request->site_agent_subscriber_id);
        $this->assertSame($this->subscriber->site_id, $request->site_id);
        $this->nextTurn = fn (): string => 'אין כרגע הצעה לאישור.';

        return $request;
    }

    private function input(): array
    {
        return $this->selector + ['discount_type' => 'percent', 'discount_value' => '20',
            'starts_at' => '2030-06-02 10:45', 'ends_at' => '2030-06-04 22:30', 'replace_existing' => false];
    }

    private function seal(string $tag): array
    {
        return ['version' => hash('sha256', $tag), 'token' => base64_encode('opaque-category-remote-'.$tag)];
    }

    private function talk(string $message): string
    {
        return app(SiteAgentConversation::class)->handle($this->subscriber, $message, 'category-sale-conversation-'.++$this->messageNumber);
    }
}
