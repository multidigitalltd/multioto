<?php

namespace Tests\Feature;

use App\Jobs\CheckSiteReputationJob;
use App\Models\Site;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\DomainReputationClient;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class DomainReputationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->zeroOrMoreTimes();
        $waha->shouldReceive('normalizeChatId')->zeroOrMoreTimes()->andReturnArg(0);
        $this->app->instance(WahaClient::class, $waha);
        config(['security.reputation.safe_browsing_key' => null]);
    }

    /**
     * Bind a client with the DNS seam stubbed — no real Spamhaus DNS in tests.
     *
     * @param  list<string>  $listedHosts  bare hosts that the DBL should report as listed
     */
    private function client(bool $spamhausProbeWorks = true, array $listedHosts = []): DomainReputationClient
    {
        $client = Mockery::mock(DomainReputationClient::class.'[dblRecords]')
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $client->shouldReceive('dblRecords')->andReturnUsing(function (string $q) use ($spamhausProbeWorks, $listedHosts): array {
            if ($q === 'test.dbl.spamhaus.org') {
                return $spamhausProbeWorks ? [['ip' => '127.0.1.2']] : [];
            }

            foreach ($listedHosts as $host) {
                if ($q === $host.'.dbl.spamhaus.org') {
                    return [['ip' => '127.0.1.4']];
                }
            }

            return [];
        });

        $this->app->instance(DomainReputationClient::class, $client);

        return $client;
    }

    private function spyTeam(): TeamNotifier
    {
        $team = Mockery::spy(TeamNotifier::class);
        $this->app->instance(TeamNotifier::class, $team);

        return $team;
    }

    public function test_urlhaus_reports_known_malware_urls(): void
    {
        Http::fake(['*urlhaus*' => Http::response([
            'query_status' => 'ok',
            'urlhaus_reference' => 'https://urlhaus.abuse.ch/host/bad.co.il/',
            'url_count' => '2',
            'urls' => [['url_status' => 'online'], ['url_status' => 'offline']],
        ])]);

        $result = $this->client(spamhausProbeWorks: false)->check('https://bad.co.il/path');

        $this->assertTrue($result['sources']['urlhaus']);
        $malware = collect($result['listings'])->firstWhere('provider', 'urlhaus');
        $this->assertSame('malware', $malware['type']);
    }

    public function test_spamhaus_catches_a_spam_only_domain_urlhaus_calls_clean(): void
    {
        // URLhaus has nothing, but the domain IS on the Spamhaus DBL — the spam
        // check must fire independently and not report the domain as clean.
        Http::fake(['*urlhaus*' => Http::response(['query_status' => 'no_results'])]);

        $result = $this->client(spamhausProbeWorks: true, listedHosts: ['bad.co.il'])->check('bad.co.il');

        $this->assertTrue($result['sources']['urlhaus']);
        $this->assertTrue($result['sources']['spamhaus']);
        $spam = collect($result['listings'])->firstWhere('provider', 'spamhaus');
        $this->assertNotNull($spam, 'a spam-listed domain was reported as clean');
        $this->assertSame('spam', $spam['type']);
    }

    public function test_a_broken_dns_resolver_reports_spamhaus_as_not_run(): void
    {
        Http::fake(['*urlhaus*' => Http::response(['query_status' => 'no_results'])]);

        // Probe fails → the DBL is unreachable from here → "not run", not "clean".
        $result = $this->client(spamhausProbeWorks: false, listedHosts: ['bad.co.il'])->check('bad.co.il');

        $this->assertFalse($result['sources']['spamhaus']);
    }

    public function test_a_clean_domain_has_no_listings(): void
    {
        Http::fake(['*urlhaus*' => Http::response(['query_status' => 'no_results'])]);

        $result = $this->client(spamhausProbeWorks: true)->check('good.co.il');

        $this->assertSame([], $result['listings']);
    }

    public function test_the_job_stores_the_result_and_alerts_on_a_new_listing(): void
    {
        Http::fake(['*urlhaus*' => Http::response([
            'query_status' => 'ok', 'url_count' => '1', 'urls' => [['url_status' => 'online']],
        ])]);
        $this->client(spamhausProbeWorks: false);
        $team = $this->spyTeam();

        $site = Site::factory()->create(['domain' => 'bad.co.il']);
        CheckSiteReputationJob::dispatchSync($site->id);

        $this->assertNotEmpty(data_get($site->fresh()->reputation_scan, 'listings'));
        $team->shouldHaveReceived('alert')->once();
    }

    public function test_no_provider_running_does_not_overwrite_the_last_result(): void
    {
        Http::fake(['*urlhaus*' => Http::response('', 500)]); // URLhaus errors → not run
        $this->client(spamhausProbeWorks: false);            // spamhaus also not run
        $team = $this->spyTeam();

        $site = Site::factory()->create([
            'domain' => 'bad.co.il',
            'reputation_scan' => ['checked_at' => now()->subDay()->toIso8601String(), 'sources' => ['urlhaus' => true], 'listings' => [['provider' => 'urlhaus', 'source' => 'URLhaus', 'type' => 'malware', 'detail' => 'x', 'link' => null]]],
        ]);

        CheckSiteReputationJob::dispatchSync($site->id);

        $this->assertCount(1, data_get($site->fresh()->reputation_scan, 'listings'));
        $team->shouldNotHaveReceived('alert');
    }

    public function test_a_failed_provider_preserves_its_previous_findings(): void
    {
        config(['security.reputation.safe_browsing_key' => 'k']);

        // Run 1: Safe Browsing flags malware, URLhaus clean.
        Http::fake([
            '*urlhaus*' => Http::response(['query_status' => 'no_results']),
            '*safebrowsing*' => Http::response(['matches' => [['threatType' => 'MALWARE']]]),
        ]);
        $this->client(spamhausProbeWorks: false);
        $team = $this->spyTeam();

        $site = Site::factory()->create(['domain' => 'bad.co.il']);
        CheckSiteReputationJob::dispatchSync($site->id);
        $this->assertNotNull(collect(data_get($site->fresh()->reputation_scan, 'listings'))->firstWhere('provider', 'safe_browsing'));

        // Run 2: Safe Browsing times out (not run); URLhaus still clean. The
        // Safe Browsing finding must be preserved, and NOT re-alerted.
        Http::fake([
            '*urlhaus*' => Http::response(['query_status' => 'no_results']),
            '*safebrowsing*' => Http::response('', 500),
        ]);
        $this->client(spamhausProbeWorks: false);
        CheckSiteReputationJob::dispatchSync($site->id);

        $this->assertNotNull(collect(data_get($site->fresh()->reputation_scan, 'listings'))->firstWhere('provider', 'safe_browsing'));
        $team->shouldHaveReceived('alert')->once();
    }
}
