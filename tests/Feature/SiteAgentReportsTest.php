<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\SendSiteAgentReportsJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentReportSchedule;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentReportBuilder;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Reports an owner orders from the bot — now, or every morning.
 *
 * A report is read as fact and acted on, so its figures must be the shop's
 * for exactly the period it names; and a scheduled one is a message we start,
 * so it must arrive once, only to somebody paying for it, and in a form Meta
 * will actually deliver.
 */
class SiteAgentReportsTest extends TestCase
{
    use RefreshDatabase;

    /** Every plugin call: [tool, arguments]. */
    private array $calls = [];

    /** Every WhatsApp send: [kind, to, body-or-params]. */
    private array $sent = [];

    private SiteAgentSubscriber $number;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Jerusalem',
            'siteagent.enabled' => true,
            'siteagent.assistant.enabled' => true,
            'siteagent.whatsapp.templates.report_ready' => '',
        ]);
        Cache::flush();

        // Tuesday 07 Oct 2026, 08:05 local.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 08:05', 'Asia/Jerusalem'));

        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'dana-shop.co.il', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://dana-shop.co.il/wp-json/md-agent/v1/mcp', 'mcp_secret' => 's',
            'mcp_capabilities' => ['server' => ['version' => '1.8.0']],
        ]);
        $plan = Plan::create([
            'name' => 'בוט', 'price_agorot' => 14900, 'message_price_agorot' => 15, 'vat_applies' => true,
            'billing_interval' => 'monthly', 'active' => true, 'is_public' => true, 'includes_site_agent' => true,
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id, 'site_id' => $site->id, 'status' => SubscriptionStatus::Active,
        ]);
        $this->number = SiteAgentSubscriber::create([
            'phone' => '972501111111', 'customer_id' => $customer->id, 'site_id' => $site->id,
            'verified_at' => now(), 'last_seen_at' => now()->subHours(2),
        ]);

        $this->fakeSite();
        $this->fakeWhatsApp();
    }

    public function test_a_daily_report_covers_yesterday_with_the_shops_own_figures(): void
    {
        $report = app(SiteAgentReportBuilder::class)->build($this->number->site, 'daily', ['sales', 'leads']);

        $this->assertContains(['wc_sales_report', ['from' => '2026-10-06', 'to' => '2026-10-06']], $this->calls);
        $this->assertContains(['wp_lead_list', ['from' => '2026-10-06', 'to' => '2026-10-06', 'limit' => 50]], $this->calls);

        $this->assertStringContainsString('דוח יומי 06/10/2026', $report['text']);
        $this->assertStringContainsString('7 הזמנות', $report['text']);
        $this->assertStringContainsString('2,340', $report['text']);
        $this->assertStringContainsString('+17%', $report['text']);
        $this->assertStringContainsString('3 לידים', $report['summary']);
        // One line, the only shape a template parameter accepts.
        $this->assertStringNotContainsString("\n", $report['summary']);
    }

    public function test_a_monthly_report_covers_the_previous_calendar_month(): void
    {
        [$from, $to] = app(SiteAgentReportBuilder::class)->period('monthly', now());

        $this->assertSame('2026-09-01', $from->format('Y-m-d'));
        $this->assertSame('2026-09-30', $to->format('Y-m-d'));
    }

    public function test_a_site_without_a_shop_gets_no_sales_section(): void
    {
        $this->number->site->update(['mcp_capabilities' => ['tools' => [['name' => 'wp_lead_list']]]]);

        $report = app(SiteAgentReportBuilder::class)->build($this->number->site->fresh(), 'daily', ['sales', 'leads']);

        $this->assertStringNotContainsString('מכירות', $report['text']);
        $this->assertStringContainsString('לידים', $report['text']);
    }

    public function test_the_next_run_follows_the_local_calendar(): void
    {
        $daily = new SiteAgentReportSchedule(['frequency' => 'daily', 'send_time' => '08:00']);
        $weekly = new SiteAgentReportSchedule(['frequency' => 'weekly', 'send_time' => '09:30', 'weekday' => 0]);
        $monthly = new SiteAgentReportSchedule(['frequency' => 'monthly', 'send_time' => '08:00']);

        $local = fn (CarbonImmutable $at): string => $at->setTimezone('Asia/Jerusalem')->format('Y-m-d H:i');

        // 08:05 now — today's 08:00 has passed.
        $this->assertSame('2026-10-08 08:00', $local($daily->nextRunAfter(now())));
        $this->assertSame('2026-10-11 09:30', $local($weekly->nextRunAfter(now())));
        $this->assertSame('2026-11-01 08:00', $local($monthly->nextRunAfter(now())));
    }

    public function test_the_owner_orders_a_daily_report_in_words(): void
    {
        $this->model(function (Closure $tool): string {
            $result = $tool('schedule_report', ['frequency' => 'daily', 'time' => '08:00', 'sections' => ['sales', 'leads']]);
            $this->assertStringContainsString('נספרת בחיוב', $result['content']);

            return 'סגור — כל בוקר בשמונה.';
        });

        $this->talk('תשלח לי כל בוקר בשמונה את המכירות והלידים');

        $schedule = SiteAgentReportSchedule::sole();
        $this->assertSame(['sales', 'leads'], $schedule->sections);
        $this->assertSame('2026-10-08 08:00', $schedule->next_run_at->setTimezone('Asia/Jerusalem')->format('Y-m-d H:i'));
    }

    public function test_a_report_now_is_sent_exactly_as_built(): void
    {
        $this->model(function (Closure $tool): string {
            $tool('report_now', ['frequency' => 'weekly']);

            return 'השבוע היה מצוין! בערך 2,000 ש"ח.';
        });

        $reply = $this->talk('דוח שבועי');

        $this->assertStringContainsString('דוח שבועי 30/09–06/10', $reply);
        // The model's paraphrase — and its rounding — never reaches the owner.
        $this->assertStringNotContainsString('בערך', $reply);
    }

    public function test_one_number_cannot_cancel_another_numbers_report(): void
    {
        $other = SiteAgentSubscriber::create([
            'phone' => '972502222222', 'customer_id' => $this->number->customer_id,
            'site_id' => $this->number->site_id, 'verified_at' => now(),
        ]);
        $theirs = $this->schedule(['site_agent_subscriber_id' => $other->id]);

        $this->model(function (Closure $tool) use ($theirs): string {
            $this->assertTrue($tool('cancel_report', ['id' => $theirs->id])['is_error']);

            return 'לא מצאתי.';
        });

        $this->talk('תבטל את הדוח');

        $this->assertTrue($theirs->exists());
        $this->assertSame(1, SiteAgentReportSchedule::count());
    }

    public function test_a_due_report_inside_the_window_goes_in_full_and_is_counted(): void
    {
        $schedule = $this->schedule(['next_run_at' => now()->subMinutes(5)]);

        $this->runReports();

        $this->assertCount(1, $this->sent);
        [$kind, $to, $body] = $this->sent[0];
        $this->assertSame('text', $kind);
        $this->assertSame('972501111111', $to);
        $this->assertStringContainsString('דוח יומי 06/10/2026', $body);

        $this->assertSame(1, SiteAgentUsage::count());
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
        $this->assertSame(SiteAgentMessage::ASSISTANT, SiteAgentMessage::sole()->role);
    }

    public function test_outside_the_window_the_report_goes_as_a_template_summary(): void
    {
        config(['siteagent.whatsapp.templates.report_ready' => 'md_report']);
        $this->number->update(['last_seen_at' => now()->subDays(3)]);
        $this->schedule(['next_run_at' => now()->subMinutes(5)]);

        $this->runReports();

        [$kind, , $params] = $this->sent[0];
        $this->assertSame('template:md_report', $kind);
        $this->assertSame('dana-shop.co.il', $params['domain']);
        $this->assertStringContainsString('7 הזמנות', $params['summary']);
        // The memory says how to answer the "דוח" that usually follows.
        $this->assertStringContainsString('report_now', SiteAgentMessage::sole()->body);
    }

    public function test_outside_the_window_without_a_template_nothing_is_sent(): void
    {
        $this->number->update(['last_seen_at' => now()->subDays(3)]);
        $schedule = $this->schedule(['next_run_at' => now()->subMinutes(5)]);

        $this->runReports();

        $this->assertSame([], $this->sent);
        // Moved on — not retried every quarter of an hour into a wall.
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
    }

    public function test_a_number_that_stopped_paying_gets_no_report(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended]);
        $this->schedule(['next_run_at' => now()->subMinutes(5)]);

        $this->runReports();

        $this->assertSame([], $this->sent);
    }

    public function test_two_overlapping_runs_send_it_once(): void
    {
        $this->schedule(['next_run_at' => now()->subMinutes(5)]);

        $this->runReports();
        $this->runReports();

        $this->assertCount(1, $this->sent);
    }

    public function test_the_rest_of_the_site_is_readable_too(): void
    {
        $this->model(function (Closure $tool): string {
            $tool('find_comments', ['status' => 'hold', 'evil' => 1]);
            $tool('list_menus', []);

            return 'יש תגובה אחת שממתינה.';
        });

        $this->talk('יש תגובות שמחכות?');

        $this->assertContains(['wp_comment_list', ['status' => 'hold']], $this->calls);
        $this->assertContains(['wp_menu_list', []], $this->calls);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function schedule(array $attributes = []): SiteAgentReportSchedule
    {
        return SiteAgentReportSchedule::create([
            'site_agent_subscriber_id' => $this->number->id,
            'site_id' => $this->number->site_id,
            'frequency' => 'daily', 'send_time' => '08:00',
            'sections' => ['sales', 'leads'],
            'next_run_at' => now()->addDay(),
            ...$attributes,
        ]);
    }

    private function runReports(): void
    {
        app()->call([new SendSiteAgentReportsJob, 'handle']);
    }

    private function talk(string $text): string
    {
        return app(SiteAgentConversation::class)->handle($this->number->fresh(), $text, 'wamid.'.md5($text.microtime()));
    }

    private function model(Closure $script): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn(null);
        $ai->shouldReceive('converse')->andReturnUsing(fn (string $s, string $p, array $t, callable $handler): ?string => $script(
            fn (string $name, array $input): array => $handler($name, $input) + ['is_error' => false],
        ));

        $this->app->instance(ClaudeClient::class, $ai);
    }

    private function fakeSite(): void
    {
        $answers = [
            'wc_sales_report' => ['paid_orders' => 7, 'gross_sales' => '2340.00', 'net_sales' => '2340.00', 'refunded' => '0.00',
                'average_order' => '334.29', 'top_products' => [['name' => 'חולצה', 'quantity' => 4, 'total' => '400.00']],
                'previous_period' => ['gross_sales' => '2000.00']],
            'wc_order_list' => ['count' => 2, 'orders' => []],
            'wp_lead_list' => ['count' => 3, 'sources' => ['elementor'], 'leads' => [
                ['date' => '2026-10-06 10:00', 'fields' => ['שם' => 'רון', 'טלפון' => '050-1']]]],
            'wcs_subscription_list' => ['count' => 12, 'subscriptions' => []],
            'wp_comment_list' => ['comments' => [['id' => 5]]],
            'wp_menu_list' => [],
        ];

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $arguments = []) use ($answers) {
            $this->calls[] = [$tool, $arguments];

            return $answers[$tool] ?? [];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->app->instance(McpClient::class, $mcp);
    }

    private function fakeWhatsApp(): void
    {
        $whatsapp = Mockery::mock(WhatsAppCloudClient::class)->makePartial();
        $whatsapp->shouldReceive('sendText')->andReturnUsing(function (string $to, string $body): string {
            $this->sent[] = ['text', $to, $body];

            return 'wamid.'.count($this->sent);
        });
        $whatsapp->shouldReceive('sendTemplate')->andReturnUsing(function (string $to, string $name, array $params = []): string {
            $this->sent[] = ['template:'.$name, $to, $params];

            return 'wamid.t'.count($this->sent);
        });
        $this->app->instance(WhatsAppCloudClient::class, $whatsapp);
    }
}
