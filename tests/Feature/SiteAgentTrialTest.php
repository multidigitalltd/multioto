<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Enums\WebhookSource;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\EndSiteAgentTrialsJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SiteAgentOrder;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Providers\SettingsServiceProvider;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Cardcom\CardTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A week free, with a card on file and nothing charged until the end.
 *
 * The promise to the buyer is "free for seven days, then the price you saw",
 * and every test is one way that promise could be broken: a card charged at
 * signup, a trial ended early by updating the card, a first charge nobody was
 * warned about, or a trial taken again and again.
 */
class SiteAgentTrialTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.vat_rate' => 0.18]);
        Queue::fake([SendSiteAgentVerificationJob::class, ChargeSubscriptionJob::class]);
        Mail::fake();

        foreach ([
            'siteagent.enabled' => '1', 'siteagent.phone_number_id' => '1234', 'siteagent.token' => 'wa-token',
            'siteagent.app_secret' => 'app-secret', 'siteagent.verify_token' => 'verify',
            'siteagent.template_verification' => 'md_verification', 'siteagent.template_paused' => 'md_paused',
            'siteagent.template_resumed' => 'md_resumed',
        ] as $key => $value) {
            Setting::put($key, $value);
        }

        SettingsServiceProvider::refreshFromDatabase();

        $this->plan = Plan::create([
            'name' => 'בוט ניהול האתר', 'price_agorot' => 14900, 'vat_applies' => true, 'billing_interval' => 'monthly',
            'active' => true, 'is_public' => true, 'includes_site_agent' => true, 'trial_days' => 7,
        ]);
    }

    public function test_the_page_states_the_trial_and_that_a_card_is_taken_but_not_charged(): void
    {
        $this->get(route('store.agent'))
            ->assertOk()
            ->assertSee('7 ימים ניסיון חינם')
            ->assertSee('מזינים כרטיס ולא מחויבים')
            // ומתי החיוב הראשון יוצא, במספר ולא ב"בתום התקופה": זה היום שעליו
            // הקונה חוזר ושואל, ועמוד שאינו אומר אותו הוא עמוד שיצר את השאלה.
            ->assertSee('ביום ה־8');
    }

    /**
     * מספר נוסף בקנייה בתקופת ניסיון — חינם עכשיו, מחויב מהחיוב הראשון.
     *
     * שתי הטעויות ההופכיות: לחייב היום על מושב בתוך תקופה שהובטחה חינם, או
     * לקשור מושב שלא ייכנס לחיוב אף פעם. הראשונה שוברת את ההבטחה, השנייה היא
     * מספר שעובד כל חודש ואינו מחויב באף אחד.
     */
    public function test_an_extra_number_bought_in_a_trial_is_free_now_and_billed_from_the_first_charge(): void
    {
        $this->plan->update(['extra_number_price_agorot' => 4900]);
        $this->fakeCardPage();

        $this->buy(['extra_phones' => ['052-7654321']]);
        $this->cardArrives();

        // שום דבר לא נגבה, ואין בכלל שורת חיוב.
        $this->assertSame(0, Charge::count());

        $subscription = SiteAgentOrder::sole()->subscription;
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        // אבל המושב נרשם, ולכן הוא ייכנס לחיוב הראשון בתום הניסיון.
        $this->assertSame(1, (int) $subscription->agent_extra_numbers);
        $this->assertSame(2, $subscription->customer->siteAgentSubscribers()->count());
    }

    public function test_a_trial_purchase_opens_a_card_page_that_charges_nothing(): void
    {
        $this->fakeCardPage();

        $this->buy()->assertRedirect('https://secure.cardcom.solutions/card/abc');

        $order = SiteAgentOrder::sole();
        $this->assertSame(7, $order->trial_days);
        $this->assertSame('lp-trial', $order->cardcom_low_profile_id);
        // No charge row at all: nothing to collect, nothing to invoice.
        $this->assertSame(0, Charge::count());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'LowProfile/Create')
            && $request['Operation'] === 'CreateTokenOnly');
    }

    public function test_the_card_arriving_opens_the_trial(): void
    {
        $this->fakeCardPage();
        $this->buy();
        $this->cardArrives();

        $order = SiteAgentOrder::sole();
        $this->assertTrue($order->isFulfilled());

        $subscription = $order->subscription;
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->trial_ends_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertNotNull($subscription->token_id);
        Queue::assertPushed(SendSiteAgentVerificationJob::class);
        Queue::assertNotPushed(ChargeSubscriptionJob::class);

        $this->get(route('store.agent.done', ['reference' => $order->reference]))
            ->assertSee('ימי ניסיון בחינם התחילו')
            ->assertSee('לא חויב');
    }

    public function test_updating_the_card_mid_trial_does_not_end_it(): void
    {
        $subscription = $this->trialEndingIn(days: 5);

        $token = PaymentToken::factory()->create(['customer_id' => $subscription->customer_id, 'status' => TokenStatus::Active]);
        app(CardTokenService::class)->makeDefault($subscription->customer, $token);

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->fresh()->status);
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_the_owner_is_reminded_two_days_before_and_only_once(): void
    {
        $this->trialEndingIn(days: 1);

        (new EndSiteAgentTrialsJob)->handle();
        (new EndSiteAgentTrialsJob)->handle();

        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->bodyText, '175.82'));
    }

    public function test_ending_a_legacy_trial_before_the_transition_job_starts_a_full_paid_month(): void
    {
        $subscription = $this->trialEndingIn(days: 0);
        $this->travel(1)->hour();

        (new EndSiteAgentTrialsJob)->handle();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('arrears', $subscription->billing_mode);
        $this->assertTrue($subscription->billing_anchor_at->equalTo($subscription->trial_ends_at));
        $this->assertTrue($subscription->next_charge_at->equalTo(SiteAgentArrearsBilling::firstChargeAt($subscription->trial_ends_at)));
        $this->assertFalse(Subscription::query()->dueForCharge()->whereKey($subscription->id)->exists());
        $this->assertSame(0, Charge::count());
    }

    public function test_a_trial_whose_card_is_gone_closes_instead_of_charging(): void
    {
        $subscription = $this->trialEndingIn(days: 0);
        $subscription->token->update(['status' => TokenStatus::Replaced, 'cardcom_token' => null]);
        $this->travel(1)->hour();

        (new EndSiteAgentTrialsJob)->handle();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertFalse($subscription->fresh()->hasFinalArrearsDebt());
    }

    public function test_a_trial_is_given_once_per_customer_and_once_per_site(): void
    {
        $this->fakeCardPage();
        $this->buy();
        $this->cardArrives();

        // The same site under a new email — no second free week.
        $this->buy(['email' => 'other@example.com']);
        $this->assertSame(0, SiteAgentOrder::query()->latest('id')->first()->trial_days);
    }

    public function test_an_old_style_trial_still_activates_when_its_card_arrives(): void
    {
        // "Trialing" without an end date: opened before a card existed. A card
        // is what makes it real, as it always was.
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id, 'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => null, 'token_id' => null, 'next_charge_at' => now()->addMonth(),
        ]);
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Active]);

        app(CardTokenService::class)->makeDefault($customer, $token);

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function buy(array $overrides = [])
    {
        return $this->post(route('store.agent.buy'), array_merge([
            'plan' => $this->plan->id, 'name' => 'דנה כהן', 'email' => 'dana@example.com',
            'phone' => '050-1234567', 'domain' => 'dana-shop.co.il',
            'install_mode' => SiteAgentOrder::INSTALL_SELF, 'terms' => '1',
        ], $overrides));
    }

    private function fakeCardPage(): void
    {
        Http::fake([
            '*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/card/abc', 'LowProfileId' => 'lp-trial']),
            // Cardcom echoes back the ReturnValue it was given — the customer
            // the page was opened for, who exists only once the form is sent.
            '*/LowProfile/GetLpResult' => fn () => Http::response([
                'ResponseCode' => 0,
                'ReturnValue' => (string) SiteAgentOrder::query()->latest('id')->value('customer_id'),
                'TokenInfo' => ['Token' => 'tok-trial', 'CardMonth' => 12, 'CardYear' => 2030],
                'TranzactionInfo' => ['Last4CardDigits' => 4580, 'CardName' => 'ויזה'],
            ]),
        ]);
    }

    private function cardArrives(): void
    {
        $event = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile', 'lp-trial', [
            'LowProfileId' => 'lp-trial',
            'ReturnValue' => (string) SiteAgentOrder::sole()->customer_id,
        ])[0];

        (new ProcessCardcomLowProfileJob($event->id))->handle();
    }

    private function trialEndingIn(int $days): Subscription
    {
        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Active]);

        return Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $this->plan->id, 'token_id' => $token->id,
            'status' => SubscriptionStatus::Trialing, 'billing_mode' => 'advance',
            'trial_ends_at' => now()->addDays($days), 'next_charge_at' => now()->addDays($days),
        ]);
    }
}
