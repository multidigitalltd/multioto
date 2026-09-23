<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Filament\Pages\ActivateSiteAgent;
use App\Filament\Resources\SiteAgentRequestResource;
use App\Filament\Resources\SiteAgentRequestResource\Pages\ListSiteAgentRequests;
use App\Filament\Resources\SiteAgentSubscriberResource\Pages\ListSiteAgentSubscribers;
use App\Jobs\SendCardCaptureLinkJob;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Jobs\SyncSiteAgentServiceStateJob;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Services\Calendar\ShabbatClock;
use App\Services\Notifications\TeamNotifier;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * סוכן האתר — stage four: the subscription that pays for it.
 *
 * The product was built agent-first: a number, a site, a conversation. What
 * makes it a product rather than a feature is the money, and the money has two
 * halves that both have to be right. Selling it must produce a subscription
 * that will actually be charged and a number that will actually answer — in one
 * action, because doing it in three is how one of the three gets skipped. And
 * when the payment stops, the customer must be TOLD the agent stopped and that
 * their site did not, once, with the thing that fixes it.
 */
class SiteAgentSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.app_secret' => 'app-secret',
            'siteagent.whatsapp.verify_token' => 'verify-me',
            'siteagent.whatsapp.token' => 'permanent-token',
            'billing.email.support_address' => 'support@multi.test',
        ]);

        // As in production: the number is a stored setting, not a runtime
        // config() override. The settings overlay reverts this key when no row
        // backs it, so a bare config() here would be wiped by the next job.
        Setting::put('siteagent.phone_number_id', '123456');
        SettingsServiceProvider::refreshFromDatabase();

        // A Wednesday. Half of what is asserted here is about a message being
        // sent, and the quiet period would suppress it — on a real Saturday the
        // suite would go red for a reason that has nothing to do with the code.
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'Asia/Jerusalem'));
    }

    /*
    | ── selling it ────────────────────────────────────────────────────────
    */

    public function test_activation_opens_the_subscription_and_binds_the_number_together(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        // A card on file: the subscription can collect itself, so there is
        // nothing to wait for and it starts live.
        $this->cardOnFile($site->customer);
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'active' => true]);

        Livewire::test(ActivateSiteAgent::class)
            ->fillForm([
                'site_id' => $site->id,
                'plan_id' => $plan->id,
                'first_charge_at' => '2026-10-01',
                'phone' => '050-1234567',
                'name' => 'דנה',
                'send_code' => true,
            ])
            ->call('activate')
            ->assertHasNoErrors();

        $subscription = Subscription::firstOrFail();
        $this->assertSame($site->customer_id, $subscription->customer_id);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame($site->id, $subscription->site_id);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->token_id, 'המנוי חייב לרשת את הכרטיס, אחרת לא ייגבה לעולם.');

        $subscriber = SiteAgentSubscriber::firstOrFail();
        // Typed as an Israeli local number, stored the way the provider sends it.
        $this->assertSame('972501234567', $subscriber->phone);
        $this->assertSame($site->customer_id, $subscriber->customer_id);
        $this->assertNull($subscriber->verified_at, 'המספר אינו מאומת עד שישלח את הקוד בחזרה.');

        Queue::assertPushed(SendSiteAgentVerificationJob::class);
        Queue::assertNotPushed(SendCardCaptureLinkJob::class);
    }

    public function test_a_customer_without_a_card_starts_a_trial_and_is_asked_for_one(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'active' => true]);

        $this->activate($site, $plan);

        // Trialing keeps it out of the charge run — there is nothing to charge —
        // and the card capture is what converts it. Opening it Active would put
        // a subscription with no token on the scheduler's list forever.
        $this->assertSame(SubscriptionStatus::Trialing, Subscription::firstOrFail()->status);
        Queue::assertPushed(SendCardCaptureLinkJob::class);
    }

    public function test_a_second_number_does_not_open_a_second_subscription(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        $this->cardOnFile($site->customer);
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'active' => true]);

        $this->activate($site, $plan, phone: '050-1111111');
        $this->activate($site, $plan, phone: '050-2222222');

        // The entitlement is the customer's. A business adding its office
        // manager is not buying the product twice.
        $this->assertSame(1, Subscription::count());
        $this->assertSame(2, SiteAgentSubscriber::count());
    }

    public function test_a_customer_in_arrears_is_not_sold_a_competing_subscription(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        $lapsed = $this->subscribe($site->customer, SubscriptionStatus::PastDue);

        $this->activate($site, Plan::factory()->create(['includes_site_agent' => true, 'active' => true]));

        // Two monthly charges for one service, with the first still being
        // chased for its arrears — and the customer paying both once a card
        // finally goes in.
        $this->assertSame(1, Subscription::count());
        $this->assertSame($lapsed->id, Subscription::firstOrFail()->id);
        $this->assertSame(1, SiteAgentSubscriber::count());
    }

    public function test_a_plan_that_does_not_include_the_agent_is_refused(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        // Re-checked on submit, not merely filtered out of the dropdown: the
        // flag can be unticked while the screen is open, and the result would
        // be a customer paying for an agent that answers "אין מנוי פעיל".
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'active' => true]);

        $component = Livewire::test(ActivateSiteAgent::class)
            ->fillForm([
                'site_id' => $site->id,
                'plan_id' => $plan->id,
                'first_charge_at' => '2026-10-01',
                'phone' => '0501234567',
            ]);

        $plan->update(['includes_site_agent' => false]);

        $component->call('activate');

        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, SiteAgentSubscriber::count());
    }

    public function test_a_site_disconnected_while_the_screen_was_open_is_refused(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'active' => true]);

        $component = Livewire::test(ActivateSiteAgent::class)
            ->fillForm([
                'site_id' => $site->id,
                'plan_id' => $plan->id,
                'first_charge_at' => '2026-10-01',
                'phone' => '0501234567',
            ]);

        $site->update(['mcp_enabled' => false]);

        $component->call('activate');

        // Selling a subscription for a service that has no hands is selling
        // nothing, and the customer finds out first.
        $this->assertSame(0, Subscription::count());
    }

    public function test_a_number_whose_access_was_removed_is_re_granted_rather_than_duplicated(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $site = $this->connectedSite();
        $this->cardOnFile($site->customer);
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'active' => true]);

        $revoked = SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'verified_at' => now()->subMonth(),
            'revoked_at' => now()->subWeek(),
            'revoked_reason' => 'עזב את התפקיד',
        ]);

        $this->activate($site, $plan);

        // The unique key is phone+site. Creating blindly would throw; the point
        // is that coming back works and is the same row, history included.
        $this->assertSame(1, SiteAgentSubscriber::count());
        $this->assertNull($revoked->fresh()->revoked_at);
        $this->assertNull($revoked->fresh()->revoked_reason);
    }

    /*
    | ── the code that binds the number ───────────────────────────────────
    */

    public function test_the_verification_code_is_sent_and_stored_only_hashed(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.code']]])]);

        $subscriber = $this->subscriber(['verified_at' => null]);

        (new SendSiteAgentVerificationJob($subscriber->id))->handle(
            app(WhatsAppCloudClient::class),
            app(TeamNotifier::class),
        );

        $subscriber->refresh();
        $stored = $subscriber->getAttributes()['verification_code'];

        $this->assertNotNull($subscriber->verification_sent_at);
        $this->assertSame(0, (int) $subscriber->verification_attempts);
        $this->assertStringNotContainsString($this->sentCode(), $stored);
        $this->assertTrue(Hash::check($this->sentCode(), $stored));
    }

    public function test_the_code_goes_out_as_an_approved_template(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.code']]])]);
        config(['siteagent.whatsapp.templates.verification' => 'site_agent_code']);

        $subscriber = $this->subscriber(['verified_at' => null]);

        (new SendSiteAgentVerificationJob($subscriber->id))->handle(
            app(WhatsAppCloudClient::class),
            app(TeamNotifier::class),
        );

        // This number has never written to us, so there is no 24-hour service
        // window open and Meta refuses free-form text (131047). Sent as text,
        // the customer simply never receives the code that activates the
        // product they just paid for.
        $body = Http::recorded()->first()[0]->data();

        $this->assertSame('template', $body['type']);
        $this->assertSame('site_agent_code', $body['template']['name']);

        $code = data_get($body, 'template.components.0.parameters.0.text');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        // Meta's authentication templates carry a copy-code button, and the
        // code has to be repeated on it or the send is rejected.
        $this->assertSame($code, data_get($body, 'template.components.1.parameters.0.text'));
        $this->assertTrue(Hash::check($code, $subscriber->fresh()->getAttributes()['verification_code']));
    }

    public function test_the_lapse_notice_goes_out_as_an_approved_template(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
        // Stored, not config()'d: the reconciliation below runs a job, and the
        // settings overlay reverts a template name that no stored row backs.
        Setting::put('siteagent.template_paused', 'site_agent_paused');
        SettingsServiceProvider::refreshFromDatabase();

        $customer = Customer::factory()->create(['phone' => '050-1234567']);
        $site = $this->connectedSite($customer);
        $this->bind($site, '972501234567');

        $subscription = $this->subscribe($customer);
        $this->sync();

        $subscription->update(['status' => SubscriptionStatus::PastDue]);
        $this->sync();

        $body = Http::recorded()->last()[0]->data();
        $parameters = array_column(data_get($body, 'template.components.0.parameters', []), 'text');

        $this->assertSame('site_agent_paused', data_get($body, 'template.name'));
        $this->assertSame($site->domain, $parameters[0] ?? null);
        // The way back rides in the template's own parameter: a template send
        // does not open a window, so no free-text message can follow it.
        $this->assertStringContainsString('/billing/update-card/', $parameters[1] ?? '');
        // Meta rejects a parameter carrying a newline, and a rejected template
        // is a customer who hears nothing at all.
        $this->assertStringNotContainsString("\n", $parameters[1] ?? '');
    }

    public function test_a_code_that_could_not_be_delivered_is_never_stamped(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $subscriber = $this->subscriber(['verified_at' => null]);

        (new SendSiteAgentVerificationJob($subscriber->id))->handle(
            app(WhatsAppCloudClient::class),
            app(TeamNotifier::class),
        );

        // Otherwise the team waits for a reply to a message nobody received,
        // and the customer waits for a service they are paying for.
        $this->assertNull($subscriber->fresh()->verification_sent_at);
        $this->assertNull($subscriber->fresh()->getAttributes()['verification_code']);
    }

    public function test_an_already_verified_number_is_not_sent_a_new_code(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.code']]])]);

        $subscriber = $this->subscriber(['verified_at' => now()]);

        (new SendSiteAgentVerificationJob($subscriber->id))->handle(
            app(WhatsAppCloudClient::class),
            app(TeamNotifier::class),
        );

        Http::assertNothingSent();
    }

    /*
    | ── when the payment stops ────────────────────────────────────────────
    */

    public function test_the_first_reconciliation_records_the_state_without_saying_anything(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        $this->sync();

        // Announcing "the service is active" to somebody activated five minutes
        // ago is noise; announcing "your subscription is not active" to
        // somebody who never had one is worse.
        Http::assertNothingSent();
        $this->assertSame(SiteAgentSubscriber::STATE_ACTIVE, $subscriber->fresh()->notified_service_state);
    }

    public function test_a_lapsed_subscription_is_announced_once_and_reassures_about_the_site(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $subscriber = $this->subscriber();
        $subscription = $this->subscribe($subscriber->customer);
        $this->sync();

        $subscription->update(['status' => SubscriptionStatus::PastDue]);
        $this->sync();

        $this->assertMessageContains('הסוכן מושהה');
        // The first thing a business owner fears is that something of theirs
        // was switched off.
        $this->assertMessageContains('האתר עצמו ממשיך לעבוד כרגיל');
        $this->assertSame(SiteAgentSubscriber::STATE_PAUSED, $subscriber->fresh()->notified_service_state);

        $before = count(Http::recorded());
        $this->sync();
        $this->sync();

        // An hourly reconciliation must not be an hourly repetition of the same
        // bad news.
        $this->assertCount($before, Http::recorded());
    }

    public function test_paying_again_is_announced_once(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $subscriber = $this->subscriber();
        $subscription = $this->subscribe($subscriber->customer, SubscriptionStatus::PastDue);
        $this->sync();

        $subscription->update(['status' => SubscriptionStatus::Active]);
        $this->sync();

        $this->assertMessageContains('חזר לעבוד');
        $this->assertSame(SiteAgentSubscriber::STATE_ACTIVE, $subscriber->fresh()->notified_service_state);
    }

    public function test_the_card_link_goes_only_to_the_number_on_the_customer_record(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $customer = Customer::factory()->create(['phone' => '050-1234567']);
        $site = $this->connectedSite($customer);

        // The owner's own number, and an employee's.
        $owner = $this->bind($site, '972501234567');
        $staff = $this->bind($site, '972509999999');

        $subscription = $this->subscribe($customer);
        $this->sync();

        $subscription->update(['status' => SubscriptionStatus::Suspended]);
        $this->sync();

        $ownerBody = $this->bodySentTo($owner->phone);
        $staffBody = $this->bodySentTo($staff->phone);

        $this->assertStringContainsString('/billing/update-card/', $ownerBody);
        // Handing an agency or an employee a card-entry page for their client's
        // business, by way of a courtesy notification, is not ours to do quietly.
        $this->assertStringNotContainsString('/billing/update-card/', $staffBody);
        $this->assertStringContainsString('דברו איתנו', $staffBody);
    }

    public function test_a_customer_who_pays_by_transfer_is_not_sent_a_card_page(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $customer = Customer::factory()->create(['phone' => '050-1234567', 'payment_method' => 'bank_transfer']);
        $site = $this->connectedSite($customer);
        $subscriber = $this->bind($site, '972501234567');

        $subscription = $this->subscribe($customer);
        $this->sync();

        $subscription->update(['status' => SubscriptionStatus::PastDue]);
        $this->sync();

        // Telling them to pay by card is telling them to pay a second time, by
        // a method they explicitly did not choose.
        $this->assertStringNotContainsString('/billing/update-card/', $this->bodySentTo($subscriber->phone));
    }

    public function test_a_notice_that_failed_to_send_is_tried_again_next_time(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $subscriber = $this->subscriber();
        $subscription = $this->subscribe($subscriber->customer);
        $this->sync();

        $subscription->update(['status' => SubscriptionStatus::Canceled]);
        $this->sync();

        // Recording an undelivered message is how a customer never finds out at
        // all — the state stays as it was so the next hour tries again.
        $this->assertSame(SiteAgentSubscriber::STATE_ACTIVE, $subscriber->fresh()->notified_service_state);
    }

    public function test_nothing_is_said_over_shabbat(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
        config(['billing.shabbat.block_automations' => true]);

        $subscriber = $this->subscriber();
        $subscription = $this->subscribe($subscriber->customer);
        $this->sync();

        // Saturday midday, and only then does the subscription lapse.
        $this->travelTo(Carbon::parse('2026-09-19 12:00:00', 'Asia/Jerusalem'));
        $subscription->update(['status' => SubscriptionStatus::Canceled]);
        $this->sync();

        Http::assertNothingSent();
        // And not filed away as "told" either, so it is still said afterwards.
        $this->assertSame(SiteAgentSubscriber::STATE_ACTIVE, $subscriber->fresh()->notified_service_state);
    }

    public function test_a_revoked_number_is_not_told_about_the_subscription(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $subscriber = $this->subscriber();
        $subscription = $this->subscribe($subscriber->customer);
        $this->sync();

        $subscriber->forceFill(['revoked_at' => now()])->save();
        $subscription->update(['status' => SubscriptionStatus::Canceled]);
        $this->sync();

        // Their access was taken away; the customer's billing is no longer any
        // of their business.
        Http::assertNothingSent();
    }

    public function test_a_subscription_moving_asks_for_the_reconciliation_at_once(): void
    {
        Queue::fake();

        $subscriber = $this->subscriber();
        $subscription = $this->subscribe($subscriber->customer);

        Queue::assertPushed(SyncSiteAgentServiceStateJob::class);
        Queue::fake();

        $subscription->update(['status' => SubscriptionStatus::PastDue]);

        // The hourly run is the net; this is so a customer is not left
        // wondering for an hour why the agent went quiet.
        Queue::assertPushed(
            SyncSiteAgentServiceStateJob::class,
            fn (SyncSiteAgentServiceStateJob $job): bool => $job->customerId === $subscriber->customer_id,
        );
    }

    /*
    | ── what the team sees ───────────────────────────────────────────────
    */

    public function test_the_screen_flags_a_trial_that_nobody_is_billing(): void
    {
        $this->actingAs(User::factory()->create());

        $subscriber = $this->subscriber(['name' => 'רונית']);
        $this->subscribe($subscriber->customer, SubscriptionStatus::Trialing);

        // The agent works, the customer is happy, and no charge will ever be
        // attempted: healthy from every other screen in the system.
        Livewire::test(ListSiteAgentSubscribers::class)
            ->assertOk()
            ->assertSee('ממתין לכרטיס', false);
    }

    public function test_changing_the_number_takes_the_verification_with_it(): void
    {
        $subscriber = $this->subscriber(['verified_at' => now(), 'name' => 'דנה']);

        // A typo corrected, or the binding handed to somebody else. Either way
        // the new number has never written to us and has proved nothing —
        // carrying the old proof across would let its very first message
        // rewrite a business's website.
        $subscriber->update(['phone' => '972509999999']);

        $subscriber->refresh();
        $this->assertNull($subscriber->verified_at);
        $this->assertFalse($subscriber->isUsable());
        $this->assertSame(0, (int) $subscriber->verification_attempts);

        // And an unrelated edit leaves the proof alone.
        $subscriber->forceFill(['verified_at' => now()])->save();
        $subscriber->update(['name' => 'רונית']);

        $this->assertNotNull($subscriber->fresh()->verified_at);
    }

    public function test_the_journal_shows_what_the_customer_asked_and_what_they_approved(): void
    {
        $this->actingAs(User::factory()->create());

        $subscriber = $this->subscriber(['name' => 'דנה']);

        SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $subscriber->site_id,
            'customer_id' => $subscriber->customer_id,
            'message' => 'תחליף את הטלפון בדף צור קשר',
            'operation' => SiteAgentRequest::OP_REPLACE,
            'preview' => 'בעמוד "צור קשר": 03-1234567 ← 03-7654321',
            'state' => SiteAgentRequest::APPLIED,
            'applied_at' => now(),
        ]);

        // When a customer rings up because "משהו באתר השתנה", this screen is
        // the answer — their own words and the exact text they said yes to.
        Livewire::test(ListSiteAgentRequests::class)
            ->assertOk()
            ->assertSee('תחליף את הטלפון', false)
            ->assertSee($subscriber->phone, false)
            ->assertSee('בוצע', false);
    }

    public function test_the_journal_cannot_be_rewritten_from_the_panel(): void
    {
        // A journal somebody can edit answers no question at all.
        $this->assertFalse(SiteAgentRequestResource::canCreate());
        $this->assertFalse(SiteAgentRequestResource::canDeleteAny());
        $this->assertSame(['index'], array_keys(SiteAgentRequestResource::getPages()));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function connectedSite(?Customer $customer = null): Site
    {
        $customer ??= Customer::factory()->create();

        return Site::factory()->create([
            'customer_id' => $customer->id,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ])->setRelation('customer', $customer);
    }

    /** @param array<string, mixed> $attributes */
    private function subscriber(array $attributes = []): SiteAgentSubscriber
    {
        $site = $this->connectedSite();

        return SiteAgentSubscriber::create(array_merge([
            'phone' => '972501234567',
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ], $attributes));
    }

    private function bind(Site $site, string $phone): SiteAgentSubscriber
    {
        return SiteAgentSubscriber::create([
            'phone' => $phone,
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ]);
    }

    private function subscribe(Customer $customer, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true])->id,
            'status' => $status,
        ]);
    }

    private function cardOnFile(Customer $customer): PaymentToken
    {
        $token = PaymentToken::factory()->create([
            'customer_id' => $customer->id,
            'status' => TokenStatus::Active,
            'expiry_month' => 12,
            'expiry_year' => (int) now()->addYears(3)->format('Y'),
        ]);

        $customer->update(['default_token_id' => $token->id]);

        return $token;
    }

    private function activate(Site $site, Plan $plan, string $phone = '050-1234567'): void
    {
        Livewire::test(ActivateSiteAgent::class)
            ->fillForm([
                'site_id' => $site->id,
                'plan_id' => $plan->id,
                'first_charge_at' => '2026-10-01',
                'phone' => $phone,
                'send_code' => true,
            ])
            ->call('activate')
            ->assertHasNoErrors();
    }

    private function sync(): void
    {
        (new SyncSiteAgentServiceStateJob)->handle(
            app(WhatsAppCloudClient::class),
            app(SiteAgentBilling::class),
            app(ShabbatClock::class),
        );
    }

    /** The code the fake provider was actually asked to deliver. */
    private function sentCode(): string
    {
        foreach (Http::recorded() as [$request]) {
            if (preg_match('/\b(\d{6})\b/', (string) data_get($request->data(), 'text.body'), $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }

    private function bodySentTo(string $phone): string
    {
        foreach (Http::recorded() as [$request]) {
            if ((string) data_get($request->data(), 'to') === $phone) {
                return (string) data_get($request->data(), 'text.body');
            }
        }

        return '';
    }

    private function assertMessageContains(string $needle): void
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains((string) data_get($request->data(), 'text.body'), $needle)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("לא נשלחה הודעה שמכילה: {$needle}");
    }
}
