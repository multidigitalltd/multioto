<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Mail\DunningNotificationMail;
use App\Models\NotificationTemplate;
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

    public function test_a_customer_with_any_arrears_subscription_gets_the_debt_copy(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        // One active + one past-due subscription for the same customer.
        $active = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $active->customer->update(['phone' => null, 'whatsapp_jid' => null, 'email' => 'multi@example.co']);
        Subscription::factory()->create(['customer_id' => $active->customer_id, 'status' => SubscriptionStatus::PastDue]);

        // Send on the ACTIVE subscription — the customer is still a debtor overall.
        app(CardCaptureLinkSender::class)->send($active->load('customer', 'plan'));

        Mail::assertSent(DunningNotificationMail::class, fn (DunningNotificationMail $m): bool => str_contains($m->bodyText, 'לא הצלחנו לחייב'));
    }

    public function test_an_edited_template_controls_the_wording(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        // The operator rewrote the card-capture email in the panel.
        NotificationTemplate::create([
            'key' => 'card.capture',
            'channel' => 'email',
            'subject' => 'נוסח מותאם ל-{{customer_name}}',
            'body' => 'שלום {{customer_name}}, הקישור: {{link}}',
            'enabled' => true,
        ]);

        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $subscription->customer->update(['name' => 'דנה', 'phone' => null, 'whatsapp_jid' => null, 'email' => 'c@example.co']);

        app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        Mail::assertSent(DunningNotificationMail::class, function (DunningNotificationMail $m): bool {
            return $m->subjectLine === 'נוסח מותאם ל-דנה'
                && str_contains($m->bodyText, 'שלום דנה, הקישור: ')
                && ! str_contains($m->bodyText, 'שמחים לצרף');
        });
    }

    public function test_a_disabled_template_skips_the_channel_but_still_returns_the_link(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        NotificationTemplate::create([
            'key' => 'card.capture', 'channel' => 'email', 'body' => 'x', 'enabled' => false,
        ]);

        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $subscription->customer->update(['phone' => null, 'whatsapp_jid' => null, 'email' => 'c@example.co']);

        $result = app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        Mail::assertNothingSent();
        $this->assertSame([], $result['sent']);
        $this->assertStringContainsString('/billing/update-card/', $result['link']);
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
