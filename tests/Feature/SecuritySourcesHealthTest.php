<?php

namespace Tests\Feature;

use App\Services\Cardcom\CardcomClient;
use App\Services\Health\IntegrationHealth;
use App\Services\Linet\LinetClient;
use App\Services\Security\DomainReputationClient;
use App\Services\Security\VulnerabilityFeedClient;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The one-click "בדיקת חיבור" for the security/reputation sources, and the
 * URLhaus Auth-Key support (abuse.ch requires a free key — without it the
 * API answers 401).
 */
class SecuritySourcesHealthTest extends TestCase
{
    use RefreshDatabase;

    /** A health service with the DNS probe stubbed (no real DNS in tests). */
    private function health(bool $spamhausWorks, bool $feedAvailable = true, ?string $feedError = null): IntegrationHealth
    {
        $feed = Mockery::mock(VulnerabilityFeedClient::class);
        $feed->shouldReceive('available')->andReturn($feedAvailable);
        $feed->shouldReceive('lastError')->andReturn($feedError);
        $this->app->instance(VulnerabilityFeedClient::class, $feed);

        $health = Mockery::mock(IntegrationHealth::class.'[spamhausProbeWorks]', [
            app(CardcomClient::class),
            app(LinetClient::class),
            app(WahaClient::class),
        ])->shouldAllowMockingProtectedMethods()->makePartial();
        $health->shouldReceive('spamhausProbeWorks')->andReturn($spamhausWorks);

        return $health;
    }

    public function test_all_sources_healthy_reports_ok_per_source(): void
    {
        config(['security.reputation.safe_browsing_key' => 'valid-key']);
        config(['security.reputation.urlhaus_auth_key' => 'abuse-key-9']);
        Http::fake([
            '*urlhaus*' => Http::response(['query_status' => 'no_results']),
            '*safebrowsing*' => Http::response(['matches' => []]),
        ]);

        $result = $this->health(spamhausWorks: true)->check('security');

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('URLhaus: נגיש ✓', $result->message);
        $this->assertStringContainsString('Spamhaus DBL: נגיש ✓', $result->message);
        $this->assertStringContainsString('המפתח תקין ✓', $result->message);
        $this->assertStringContainsString('פיד פגיעויות (Wordfence): נגיש ✓', $result->message);

        // The connection test must probe URLhaus exactly like the real scans —
        // with the configured abuse.ch Auth-Key attached.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'urlhaus')
            && $request->hasHeader('Auth-Key', 'abuse-key-9'));
    }

    public function test_a_401_from_urlhaus_points_at_the_missing_auth_key(): void
    {
        config(['security.reputation.safe_browsing_key' => '']);
        Http::fake(['*urlhaus*' => Http::response('', 401)]);

        $result = $this->health(spamhausWorks: false, feedAvailable: false, feedError: 'הפיד החזיר סטטוס HTTP 403.')->check('security');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('URLhaus: נדרש Auth-Key חינמי מ-auth.abuse.ch', $result->message);
        $this->assertStringContainsString('resolver', $result->message);
        $this->assertStringContainsString('לא הוגדר מפתח', $result->message);
        $this->assertStringContainsString('HTTP 403', $result->message);
    }

    public function test_the_reputation_client_sends_the_auth_key_and_explains_a_401(): void
    {
        config(['security.reputation.urlhaus_auth_key' => 'abuse-key-1']);
        Http::fake(['*urlhaus*' => Http::response(['query_status' => 'no_results'])]);

        $client = Mockery::mock(DomainReputationClient::class.'[dblRecords]')
            ->shouldAllowMockingProtectedMethods()->makePartial();
        $client->shouldReceive('dblRecords')->andReturn([]);

        $result = $client->check('example.co.il');

        $this->assertTrue($result['sources']['urlhaus']);
        Http::assertSent(fn ($request) => $request->hasHeader('Auth-Key', 'abuse-key-1'));
    }

    public function test_a_403_with_a_key_points_at_a_wrong_or_partial_key(): void
    {
        config(['security.reputation.urlhaus_auth_key' => 'wrong-key']);
        config(['security.reputation.safe_browsing_key' => '']);
        Http::fake(['*urlhaus*' => Http::response('', 403)]);

        $result = $this->health(spamhausWorks: true)->check('security');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('ה-Auth-Key נדחה (403)', $result->message);

        // And the reputation client explains the same in the event log.
        $client = Mockery::mock(DomainReputationClient::class.'[dblRecords]')
            ->shouldAllowMockingProtectedMethods()->makePartial();
        $client->shouldReceive('dblRecords')->andReturn([]);
        $check = $client->check('example.co.il');
        $this->assertStringContainsString('שגוי או חלקי', $check['errors']['urlhaus']);
    }

    public function test_a_401_without_a_key_explains_where_to_get_one(): void
    {
        config(['security.reputation.urlhaus_auth_key' => '']);
        Http::fake(['*urlhaus*' => Http::response('', 401)]);

        $client = Mockery::mock(DomainReputationClient::class.'[dblRecords]')
            ->shouldAllowMockingProtectedMethods()->makePartial();
        $client->shouldReceive('dblRecords')->andReturn([]);

        $result = $client->check('example.co.il');

        $this->assertFalse($result['sources']['urlhaus']);
        $this->assertStringContainsString('auth.abuse.ch', $result['errors']['urlhaus']);
    }
}
