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

    public function test_the_renewal_reminder_uses_the_stored_whatsapp_jid_without_a_phone(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        // Delivered to the JID even though there is no phone number.
        $waha->shouldReceive('sendMessage')->once()->with('12345@c.us', Mockery::type('string'));
        $this->app->instance(WahaClient::class, $waha);

        $customer = Customer::factory()->create(['email' => null, 'phone' => null, 'whatsapp_jid' => '12345@c.us']);
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain_expiry_at' => now()->addDays(15)->toDateString(),
        ]);

        SendDomainRenewalReminderJob::dispatchSync($site->id);

        Mail::assertNothingSent();
        $this->assertSame(1, NotificationLog::where('type', NotificationType::DomainRenewal->value)
            ->where('channel', 'whatsapp')->count());
    }

    public function test_a_failed_email_does_not_block_the_whatsapp_reminder(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->once(); // still sent despite the email failure
        $this->app->instance(WahaClient::class, $waha);

        $customer = Customer::factory()->create(['email' => 'owner@biz.co.il', 'phone' => '0501234567']);
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain_expiry_at' => now()->addDays(15)->toDateString(),
        ]);

        SendDomainRenewalReminderJob::dispatchSync($site->id);

        // Email recorded as failed, WhatsApp recorded as sent.
        $this->assertSame(1, NotificationLog::where('channel', 'email')->where('status', 'failed')->count());
        $this->assertSame(1, NotificationLog::where('channel', 'whatsapp')->where('status', 'sent')->count());
    }

    public function test_the_reminder_sends_only_on_the_chosen_channels(): void
    {
        Mail::fake();
        // Email-only was picked, so WhatsApp must NOT be sent even though a phone
        // exists.
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldNotReceive('sendMessage');
        $this->app->instance(WahaClient::class, $waha);

        $customer = Customer::factory()->create(['email' => 'owner@biz.co.il', 'phone' => '0501234567']);
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain_expiry_at' => now()->addDays(20)->toDateString(),
        ]);

        SendDomainRenewalReminderJob::dispatchSync($site->id, ['email']);

        Mail::assertSent(NotificationMail::class);
        $this->assertSame(0, NotificationLog::where('channel', 'whatsapp')->count());
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
