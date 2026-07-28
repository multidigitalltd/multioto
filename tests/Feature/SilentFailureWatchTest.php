<?php

namespace Tests\Feature;

use App\Jobs\CheckSiteLayoutJob;
use App\Jobs\CheckStoreSalesJob;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\LayoutFingerprint;
use App\Services\Security\SalesPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The two failures an uptime monitor is blind to: a store that answers 200 but
 * has stopped selling, and a homepage that answers 200 but looks wrecked.
 */
class SilentFailureWatchTest extends TestCase
{
    use RefreshDatabase;

    private function storeSite(?array $pulse = null): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://shop.example.com/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => [['name' => 'wc_order_stats_get']]],
            'store_pulse' => $pulse,
        ]);
    }

    /** wc_order_stats_get payload: $daily orders/day, then the last 24h. */
    private function mcpStats(array $daily, int $orders24h, int $paid24h): McpClient
    {
        $payload = json_encode([
            'days' => count($daily),
            'daily' => collect($daily)->mapWithKeys(fn (array $d, int $i): array => [
                now()->subDays(count($daily) - $i)->toDateString() => $d,
            ])->all(),
            'last_24h' => ['orders' => $orders24h, 'paid' => $paid24h, 'by_status' => []],
            'currency' => 'ILS',
        ]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturn(['content' => [['type' => 'text', 'text' => $payload]]]);
        $mcp->shouldReceive('textContent')->andReturn((string) $payload);

        return $mcp;
    }

    /** Five normal days plus one incomplete "today" the median must ignore. */
    private function normalWeek(): array
    {
        return [
            ['orders' => 6, 'paid' => 5],
            ['orders' => 4, 'paid' => 4],
            ['orders' => 7, 'paid' => 6],
            ['orders' => 5, 'paid' => 5],
            ['orders' => 6, 'paid' => 5],
            ['orders' => 0, 'paid' => 0], // today, still filling
        ];
    }

    public function test_a_store_that_stopped_taking_orders_alerts_and_is_logged(): void
    {
        $site = $this->storeSite();

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->withArgs(fn (string $title): bool => str_contains($title, 'הפסיקה לקבל הזמנות'));

        (new CheckStoreSalesJob($site->id))->handle($this->mcpStats($this->normalWeek(), 0, 0), new SalesPulse, $team);

        $this->assertSame('store_silent', $site->fresh()->store_pulse['status']);
        $this->assertDatabaseHas('site_events', ['site_id' => $site->id, 'type' => 'store_silent', 'severity' => 'critical']);
    }

    public function test_orders_arriving_but_none_paid_is_reported_as_a_gateway_failure(): void
    {
        $site = $this->storeSite();

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->withArgs(fn (string $title): bool => str_contains($title, 'לא שולמה'));

        (new CheckStoreSalesJob($site->id))->handle($this->mcpStats($this->normalWeek(), 4, 0), new SalesPulse, $team);

        $this->assertSame('store_payments', $site->fresh()->store_pulse['status']);
    }

    public function test_a_quiet_shop_is_never_accused_of_a_silent_failure(): void
    {
        // A shop that normally does well under one order a day has no baseline
        // to fail against — zero orders is simply a normal day.
        $site = $this->storeSite();
        $quiet = array_fill(0, 6, ['orders' => 0, 'paid' => 0]);
        $quiet[1] = ['orders' => 1, 'paid' => 1];

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckStoreSalesJob($site->id))->handle($this->mcpStats($quiet, 0, 0), new SalesPulse, $team);

        $this->assertSame('ok', $site->fresh()->store_pulse['status']);
    }

    public function test_the_same_failure_does_not_alert_twice_inside_the_cooldown(): void
    {
        $site = $this->storeSite([
            'last_alert_kind' => 'store_silent',
            'last_alert_at' => now()->subHours(2)->toIso8601String(),
        ]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckStoreSalesJob($site->id))->handle($this->mcpStats($this->normalWeek(), 0, 0), new SalesPulse, $team);

        // Still recorded as failing — just not re-announced.
        $this->assertSame('store_silent', $site->fresh()->store_pulse['status']);
    }

    public function test_a_broken_layout_alerts_and_keeps_the_last_good_baseline(): void
    {
        $good = '<html><head><title>x</title></head><body><header>h</header><nav>n</nav>'
            .str_repeat('<img src="a.jpg">', 20).str_repeat('<a href="/x">l</a>', 20)
            .str_repeat('<h2>t</h2>', 6).str_repeat('.', 9000).'<footer>f</footer></body></html>';

        $site = Site::factory()->create(['monitor_enabled' => true, 'domain' => 'example.co.il']);

        // One sequence for both runs: a repeated Http::fake() would NOT replace
        // the first stub, so the second fetch would silently re-serve the good
        // page and the test would pass for the wrong reason.
        Http::fake(['*' => Http::sequence()
            ->push($good)
            ->push('<html><body><p>hello</p></body></html>')]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');
        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $team);

        $baseline = $site->fresh()->layout_snapshot['fingerprint'];
        $this->assertSame(20, $baseline['images']);

        // A theme update wipes the header, the menu and most of the images.
        $team2 = Mockery::mock(TeamNotifier::class);
        $team2->shouldReceive('alert')->once()->withArgs(fn (string $title, string $body): bool => str_contains($title, 'מבנה העמוד')
            && str_contains($body, 'הכותרת העליונה'));

        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $team2);

        $snapshot = $site->fresh()->layout_snapshot;
        $this->assertSame('broken', $snapshot['status']);
        // The baseline must stay the last GOOD page, not the broken one.
        $this->assertSame(20, $snapshot['fingerprint']['images']);
        $this->assertDatabaseHas('site_events', ['site_id' => $site->id, 'type' => 'layout_broken']);
    }

    public function test_an_ordinary_content_edit_does_not_look_like_a_broken_layout(): void
    {
        $page = fn (string $extra): string => '<html><body><header>h</header><nav>n</nav>'
            .str_repeat('<img src="a.jpg">', 12).str_repeat('<a href="/x">l</a>', 15)
            .str_repeat('<h2>t</h2>', 5).$extra.str_repeat('.', 8000).'<footer>f</footer></body></html>';

        $site = Site::factory()->create(['monitor_enabled' => true, 'domain' => 'example.co.il']);

        // Sequence, not two fakes — see the note in the broken-layout test.
        Http::fake(['*' => Http::sequence()
            ->push($page(''))
            ->push($page('<p>מבצע חדש</p><img src="b.jpg">'))]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');
        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $team);

        // A new paragraph and one extra image — a normal edit.
        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $team);

        $this->assertSame('ok', $site->fresh()->layout_snapshot['status']);
    }

    public function test_a_layout_that_stays_broken_alerts_only_once(): void
    {
        $good = '<html><body><header>h</header><nav>n</nav>'
            .str_repeat('<img src="a.jpg">', 20).str_repeat('<a href="/x">l</a>', 20)
            .str_repeat('<h2>t</h2>', 6).str_repeat('.', 9000).'<footer>f</footer></body></html>';
        $broken = '<html><body><p>hello</p></body></html>';

        $site = Site::factory()->create(['monitor_enabled' => true, 'domain' => 'example.co.il']);

        Http::fake(['*' => Http::sequence()->push($good)->push($broken)->push($broken)]);

        $silent = Mockery::mock(TeamNotifier::class);
        $silent->shouldNotReceive('alert');
        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $silent);

        $loud = Mockery::mock(TeamNotifier::class);
        $loud->shouldReceive('alert')->once();
        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $loud);

        // Second broken run: same breakage → no second alarm, no duplicate row.
        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $silent);

        $this->assertSame(1, SiteEvent::where('site_id', $site->id)->where('type', 'layout_broken')->count());
    }

    public function test_one_surviving_landmark_does_not_mask_the_others(): void
    {
        // A page that kept only its navigation must still report the missing
        // header and footer — a shared ARIA check would hide both.
        $fingerprint = new LayoutFingerprint;

        $before = $fingerprint->make('<div role="banner">h</div><div role="navigation">n</div><div role="contentinfo">f</div>');
        $after = $fingerprint->make('<div role="navigation">n</div>');

        $reasons = $fingerprint->breakages($before, $after);

        $this->assertContains('הכותרת העליונה (header) נעלמה מהעמוד', $reasons);
        $this->assertContains('הכותרת התחתונה (footer) נעלמה מהעמוד', $reasons);
    }

    public function test_downtime_never_produces_a_layout_alert(): void
    {
        $site = Site::factory()->create([
            'monitor_enabled' => true,
            'domain' => 'example.co.il',
            'layout_snapshot' => ['fingerprint' => ['images' => 20, 'links' => 20, 'headings' => 6, 'bytes' => 9000, 'landmarks' => ['header' => true, 'nav' => true, 'footer' => true]], 'status' => 'ok'],
        ]);

        Http::fake(['*' => Http::response('', 503)]);
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckSiteLayoutJob($site->id))->handle(new LayoutFingerprint, $team);

        // Untouched: an outage is the uptime monitor's story to tell.
        $this->assertSame('ok', $site->fresh()->layout_snapshot['status']);
    }
}
