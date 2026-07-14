<?php

namespace Tests\Feature;

use App\Jobs\CheckDomainExpiryJob;
use App\Models\Site;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DomainExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_expiry_date_from_rdap(): void
    {
        Http::fake([
            'rdap.org/domain/example.com' => Http::response([
                'events' => [
                    ['eventAction' => 'registration', 'eventDate' => '2010-01-01T00:00:00Z'],
                    ['eventAction' => 'expiration', 'eventDate' => '2027-03-15T00:00:00Z'],
                ],
            ]),
        ]);

        // A sub-host is reduced to its registrable domain for the lookup.
        $expiresAt = app(DomainExpiry::class)->expiresAt('www.example.com');

        $this->assertSame('2027-03-15', $expiresAt?->toDateString());
    }

    public function test_it_returns_null_when_rdap_has_no_data(): void
    {
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $this->assertNull(app(DomainExpiry::class)->expiresAt('no-such-tld.zzz'));
    }

    public function test_domain_expiry_alerts_the_team_once_then_re_arms_after_renewal(): void
    {
        config(['billing.monitoring.domain_warn_days' => 30]);

        $site = Site::factory()->create(['domain' => 'expiring.example.com']);

        $domains = Mockery::mock(DomainExpiry::class);
        // Inside the window (10 days), still inside, then renewed (400 days out).
        $domains->shouldReceive('expiresAt')->andReturn(
            now()->addDays(10),
            now()->addDays(10),
            now()->addDays(400),
        );
        $this->app->instance(DomainExpiry::class, $domains);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once(); // exactly once across the two in-window runs
        $this->app->instance(TeamNotifier::class, $team);

        CheckDomainExpiryJob::dispatchSync($site->id);
        $this->assertNotNull($site->refresh()->domain_expiry_at);
        $this->assertNotNull($site->domain_alerted_at);

        // Second run inside the window: no second alert (already armed).
        CheckDomainExpiryJob::dispatchSync($site->id);

        // Renewed: the flag clears so a future expiry can alert again.
        CheckDomainExpiryJob::dispatchSync($site->id);
        $this->assertNull($site->refresh()->domain_alerted_at);
    }
}
