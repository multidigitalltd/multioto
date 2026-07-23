<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Jobs\CheckDomainExpiryJob;
use App\Jobs\SendDomainRenewalReminderJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Site;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Notifications\TeamNotifier;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    public function test_the_renewal_reminder_reaches_the_customer_by_email_and_whatsapp(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->once();
        $this->app->instance(WahaClient::class, $waha);

        $customer = Customer::factory()->create(['email' => 'owner@biz.co.il', 'phone' => '0501234567']);
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain' => 'client-domain.co.il',
            'domain_expiry_at' => now()->addDays(20)->toDateString(),
        ]);

        SendDomainRenewalReminderJob::dispatchSync($site->id);

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo('owner@biz.co.il')
            && str_contains($m->bodyText, 'client-domain.co.il'));
        $this->assertSame(1, NotificationLog::where('type', NotificationType::DomainRenewal->value)
            ->where('channel', 'email')->count());
    }

    public function test_the_renewal_reminder_is_a_noop_without_a_known_expiry(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'owner@biz.co.il']);
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain_expiry_at' => null]);

        SendDomainRenewalReminderJob::dispatchSync($site->id);

        Mail::assertNothingSent();
    }
}
