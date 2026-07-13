<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Mail\DunningNotificationMail;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CardCaptureLinkSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.waha.base_url' => 'http://waha:3000', 'billing.waha.session' => 'default']);
        // The app runs in Hebrew in production; set it so lang/he/*.php resolve.
        $this->app->setLocale('he');
    }

    public function test_it_reports_each_channel_that_delivered(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        $subscription = Subscription::factory()->create();
        $subscription->customer->update(['phone' => '+972501234567', 'email' => 'c@example.co']);

        $result = app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        $this->assertContains('וואטסאפ', $result['sent']);
        $this->assertContains('אימייל', $result['sent']);
        $this->assertSame([], $result['failed']);
        $this->assertStringContainsString('/billing/update-card/', $result['link']);
    }

    public function test_an_active_subscription_gets_the_welcome_toned_message(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $subscription->customer->update(['phone' => null, 'whatsapp_jid' => null, 'email' => 'c@example.co']);

        app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        Mail::assertSent(DunningNotificationMail::class, fn (DunningNotificationMail $m): bool => str_contains($m->bodyText, 'שמחים לצרף'));
    }

    public function test_a_debtor_subscription_gets_the_debt_toned_message(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::PastDue]);
        $subscription->customer->update(['phone' => null, 'whatsapp_jid' => null, 'email' => 'debtor@example.co']);

        app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        // Debt-toned copy ("we couldn't charge the payment"), not the welcome line.
        Mail::assertSent(DunningNotificationMail::class, function (DunningNotificationMail $m): bool {
            return str_contains($m->bodyText, 'לא הצלחנו לחייב') && ! str_contains($m->bodyText, 'שמחים לצרף');
        });
    }

    public function test_it_reports_a_whatsapp_failure_instead_of_claiming_success(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['error' => 'session not working'], 500)]);

        $subscription = Subscription::factory()->create();
        $subscription->customer->update(['phone' => '+972501234567', 'email' => null, 'whatsapp_jid' => null]);

        $result = app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        $this->assertSame([], $result['sent']);
        $this->assertNotEmpty($result['failed']);
        $this->assertStringContainsString('וואטסאפ', $result['failed'][0]);
    }
}
