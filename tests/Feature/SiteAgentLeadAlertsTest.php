<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\SendSiteAgentLeadAlertsJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentLeadAlerts;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * "תודיע לי על כל ליד חדש": each new lead announced once, minutes after it
 * arrives — never the old ones, never twice, never a flood, and never to a
 * number that stopped paying.
 */
class SiteAgentLeadAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** The site's leads, newest first, as wp_lead_list returns them. */
    private array $leads = [];

    /** Every WhatsApp send: [kind, to, body-or-params]. */
    private array $sent = [];

    /** wp_lead_list calls made. */
    private int $reads = 0;

    private SiteAgentSubscriber $number;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siteagent.enabled' => true,
            'siteagent.assistant.enabled' => true,
            'siteagent.whatsapp.templates.report_ready' => '',
        ]);
        Cache::flush();

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

        $this->leads = [$this->lead(1, 'יעל')];
        $this->fakeSite();
        $this->fakeWhatsApp();
    }

    public function test_switching_alerts_on_does_not_announce_the_leads_already_there(): void
    {
        $this->model(function (Closure $tool): string {
            $result = $tool('lead_alerts', ['on' => true]);
            $this->assertStringContainsString('נספרת בחיוב', $result['content']);

            return 'מעכשיו אודיע לך על כל ליד חדש.';
        });
        $this->talk('תודיע לי על כל ליד חדש');

        $this->assertTrue($this->number->fresh()->lead_alerts);

        $this->runAlerts();
        $this->assertSame([], $this->sent);
    }

    public function test_a_new_lead_is_announced_once_with_its_fields_and_counted(): void
    {
        $this->enable();
        array_unshift($this->leads, $this->lead(2, 'רון'));

        $this->runAlerts();
        $this->runAlerts();

        $this->assertCount(1, $this->sent);
        [$kind, $to, $body] = $this->sent[0];
        $this->assertSame('text', $kind);
        $this->assertSame('972501111111', $to);
        $this->assertStringContainsString('ליד חדש', $body);
        $this->assertStringContainsString('רון', $body);
        $this->assertStringContainsString('050-2', $body);
        $this->assertSame(1, SiteAgentUsage::count());
    }

    public function test_a_burst_is_capped_and_the_rest_are_counted(): void
    {
        $this->enable();

        foreach (range(2, 8) as $id) {
            array_unshift($this->leads, $this->lead($id, "ליד {$id}"));
        }

        $this->runAlerts();

        $this->assertCount(3, $this->sent);
        // Oldest first, as they arrived.
        $this->assertStringContainsString('ליד 2', $this->sent[0][2]);
        $this->assertStringContainsString('ועוד 4 לידים', $this->sent[2][2]);

        $this->runAlerts();
        $this->assertCount(3, $this->sent);
    }

    public function test_a_burst_beyond_one_read_is_said_not_swallowed(): void
    {
        $this->enable();

        // A full read, every lead in it new: older new ones may lie beyond it.
        $this->leads = array_map(fn (int $id): array => $this->lead($id, "ליד {$id}"), range(100, 51));

        $this->runAlerts();

        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString('ועוד לפחות 47 לידים', $this->sent[2][2]);
    }

    public function test_outside_the_window_the_leads_go_as_one_template(): void
    {
        config(['siteagent.whatsapp.templates.report_ready' => 'md_report']);
        $this->number->update(['last_seen_at' => now()->subDays(3)]);
        $this->enable();
        array_unshift($this->leads, $this->lead(2, 'רון'), $this->lead(3, 'דנה'));

        $this->runAlerts();

        $this->assertCount(1, $this->sent);
        [$kind, , $params] = $this->sent[0];
        $this->assertSame('template:md_report', $kind);
        $this->assertSame('2 לידים חדשים', $params['title']);
        $this->assertSame('dana-shop.co.il', $params['domain']);
        $this->assertStringNotContainsString("\n", $params['summary']);
    }

    public function test_a_lead_that_could_not_be_sent_is_tried_again(): void
    {
        $this->enable();
        array_unshift($this->leads, $this->lead(2, 'רון'));

        $this->fakeWhatsApp(failing: true);
        $this->runAlerts();
        $this->assertSame([], $this->sent);

        $this->fakeWhatsApp();
        $this->runAlerts();
        $this->assertCount(1, $this->sent);
    }

    public function test_a_number_that_stopped_paying_gets_no_alerts(): void
    {
        $this->enable();
        array_unshift($this->leads, $this->lead(2, 'רון'));
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended]);

        $this->runAlerts();

        $this->assertSame([], $this->sent);
        // Read once, when the alerts were switched on — never for a number that is not entitled.
        $this->assertSame(1, $this->reads);
    }

    public function test_switching_off_stops_them(): void
    {
        $this->enable();
        $this->model(function (Closure $tool): string {
            $tool('lead_alerts', ['on' => false]);

            return 'הופסק.';
        });
        $this->talk('תפסיק להודיע על לידים');

        array_unshift($this->leads, $this->lead(2, 'רון'));
        $this->runAlerts();

        $this->assertFalse($this->number->fresh()->lead_alerts);
        $this->assertSame([], $this->sent);
    }

    public function test_at_the_owners_message_ceiling_alerts_wait(): void
    {
        $this->enable();
        Subscription::query()->update(['site_agent_message_cap' => 1]);
        app(SiteAgentUsageMeter::class)->record($this->number, 'wamid.earlier');
        array_unshift($this->leads, $this->lead(2, 'רון'));

        $this->runAlerts();

        $this->assertSame([], $this->sent);

        // Raised: the lead that waited goes out.
        Subscription::query()->update(['site_agent_message_cap' => 10]);
        $this->runAlerts();
        $this->assertCount(1, $this->sent);
    }

    public function test_a_burst_stops_at_the_ceiling_alert_by_alert(): void
    {
        $this->enable();
        Subscription::query()->update(['site_agent_message_cap' => 10]);
        $meter = app(SiteAgentUsageMeter::class);

        foreach (range(1, 9) as $i) {
            $meter->record($this->number, "wamid.earlier-{$i}");
        }

        array_unshift($this->leads, $this->lead(2, 'רון'), $this->lead(3, 'דנה'), $this->lead(4, 'גיל'));

        $this->runAlerts();

        // One message left under the ceiling: one alert, and no more billed.
        $alerts = array_filter($this->sent, fn (array $sent): bool => str_contains((string) $sent[2], 'ליד חדש'));
        $this->assertCount(1, $alerts);
        $this->assertSame(10, SiteAgentUsage::where('billable', true)->count());
    }

    public function test_crossing_eighty_percent_through_an_alert_says_so(): void
    {
        $this->enable();
        Subscription::query()->update(['site_agent_message_cap' => 5]);
        $meter = app(SiteAgentUsageMeter::class);

        foreach (range(1, 3) as $i) {
            $meter->record($this->number, "wamid.earlier-{$i}");
        }

        array_unshift($this->leads, $this->lead(2, 'רון'));
        $this->runAlerts();

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('מתוך 5 ההודעות', $this->sent[1][2]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function enable(): void
    {
        $this->assertNull(app(SiteAgentLeadAlerts::class)->enable($this->number, $this->number->site));
    }

    /** @return array<string, mixed> */
    private function lead(int $id, string $name): array
    {
        return ['source' => 'elementor', 'id' => (string) $id, 'form' => 'צור קשר', 'date' => '2026-10-07 10:0'.($id % 10),
            'fields' => ['שם' => $name, 'טלפון' => "050-{$id}"]];
    }

    private function runAlerts(): void
    {
        app()->call([new SendSiteAgentLeadAlertsJob, 'handle']);
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
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool) {
            if ($tool !== 'wp_lead_list') {
                return [];
            }

            $this->reads++;

            return ['count' => count($this->leads), 'sources' => ['elementor'], 'leads' => $this->leads];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->app->instance(McpClient::class, $mcp);
    }

    private function fakeWhatsApp(bool $failing = false): void
    {
        $whatsapp = Mockery::mock(WhatsAppCloudClient::class)->makePartial();
        $whatsapp->shouldReceive('sendText')->andReturnUsing(function (string $to, string $body) use ($failing): ?string {
            if ($failing) {
                return null;
            }

            $this->sent[] = ['text', $to, $body];

            return 'wamid.'.count($this->sent);
        });
        $whatsapp->shouldReceive('sendTemplate')->andReturnUsing(function (string $to, string $name, array $params = []) use ($failing): ?string {
            if ($failing) {
                return null;
            }

            $this->sent[] = ['template:'.$name, $to, $params];

            return 'wamid.t'.count($this->sent);
        });
        $this->app->instance(WhatsAppCloudClient::class, $whatsapp);
    }
}
