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

    private function fakeUrlhaus(array $body): void
    {
        Http::fake(['*urlhaus*' => Http::response($body)]);
    }

    private function spyTeam(): TeamNotifier
    {
        $team = Mockery::spy(TeamNotifier::class);
        $this->app->instance(TeamNotifier::class, $team);

        return $team;
    }

    public function test_a_flagged_host_reports_malware_and_spam_listings(): void
    {
        $this->fakeUrlhaus([
            'query_status' => 'ok',
            'urlhaus_reference' => 'https://urlhaus.abuse.ch/host/bad.co.il/',
            'url_count' => '2',
            'urls' => [['url_status' => 'online'], ['url_status' => 'offline']],
            'blacklists' => ['spamhaus_dbl' => 'abused_legit_malware', 'surbl' => 'not listed'],
        ]);

        $result = app(DomainReputationClient::class)->check('https://bad.co.il/path');

        $this->assertTrue($result['sources']['urlhaus']);
        $types = collect($result['listings'])->pluck('type');
        $this->assertTrue($types->contains('malware'));
        $this->assertTrue($types->contains('spam'));
        // "not listed" entries are ignored.
        $this->assertFalse(collect($result['listings'])->pluck('source')->contains('SURBL'));
    }

    public function test_a_clean_host_has_no_listings(): void
    {
        $this->fakeUrlhaus(['query_status' => 'no_results']);

        $result = app(DomainReputationClient::class)->check('good.co.il');

        $this->assertTrue($result['sources']['urlhaus']);
        $this->assertSame([], $result['listings']);
    }

    public function test_the_job_stores_the_result_and_alerts_on_a_new_listing(): void
    {
        $this->fakeUrlhaus([
            'query_status' => 'ok',
            'url_count' => '1',
            'urls' => [['url_status' => 'online']],
            'blacklists' => ['spamhaus_dbl' => 'listed'],
        ]);
        $team = $this->spyTeam();

        $site = Site::factory()->create(['domain' => 'bad.co.il']);
        CheckSiteReputationJob::dispatchSync($site->id);

        $this->assertNotEmpty(data_get($site->fresh()->reputation_scan, 'listings'));
        $team->shouldHaveReceived('alert')->once();
    }

    public function test_an_unavailable_source_does_not_overwrite_the_last_result(): void
    {
        // URLhaus errors → source did not run.
        Http::fake(['*urlhaus*' => Http::response('', 500)]);
        $team = $this->spyTeam();

        $site = Site::factory()->create([
            'domain' => 'bad.co.il',
            'reputation_scan' => ['checked_at' => now()->subDay()->toIso8601String(), 'sources' => ['urlhaus' => true], 'listings' => [['source' => 'URLhaus', 'type' => 'malware', 'detail' => '1 כתובות זדוניות פעילות', 'link' => null]]],
        ]);

        CheckSiteReputationJob::dispatchSync($site->id);

        // The previous listing is preserved; no alert on an outage.
        $this->assertCount(1, data_get($site->fresh()->reputation_scan, 'listings'));
        $team->shouldNotHaveReceived('alert');
    }

    public function test_the_same_listing_is_not_re_alerted_on_the_next_run(): void
    {
        $this->fakeUrlhaus([
            'query_status' => 'ok',
            'url_count' => '1',
            'urls' => [['url_status' => 'online']],
        ]);
        $team = $this->spyTeam();

        $site = Site::factory()->create(['domain' => 'bad.co.il']);
        CheckSiteReputationJob::dispatchSync($site->id);
        CheckSiteReputationJob::dispatchSync($site->id);

        $team->shouldHaveReceived('alert')->once();
    }
}
