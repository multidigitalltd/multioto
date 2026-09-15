<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Jobs\PrunePendingSignupsJob;
use App\Models\Customer;
use App\Models\PendingSignup;
use App\Models\SignupInvite;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * No card, no customer.
 *
 * The public form used to save the customer and THEN show the card page, so
 * closing the tab left a record that looked exactly like a finished signup and
 * had nothing behind it to collect from. The card now comes first, and these
 * tests are about the one property that follows: at no point does a customer
 * exist without the card they were required to leave.
 *
 * The single exception is an invite a manager issued with the card waived —
 * recorded, single-use, and attributed to the person who granted it.
 */
class SignupRequiresCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
    }

    /**
     * What Cardcom answers for this test.
     *
     * Registered per test, never in setUp: Http::fake matches the FIRST stub
     * that fits, so a default registered up here would quietly win over the
     * outage a test is trying to describe — and the test would pass while
     * exercising the opposite case.
     *
     * @param  array<string, mixed>  $response
     */
    private function cardcomReturns(array $response = ['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x', 'LowProfileId' => 'lp-1'], int $status = 200): void
    {
        Http::fake(['*' => Http::response($response, $status)]);
    }

    public function test_abandoning_the_card_page_leaves_no_customer_behind(): void
    {
        $this->cardcomReturns();
        $this->post(route('signup.store'), $this->payload())->assertRedirectContains('/join/card/');

        // They open the card page and close the tab. That is the whole scenario
        // this flow was rebuilt for.
        $this->get(route('signup.card', ['pending' => PendingSignup::sole()->token]))->assertOk();

        $this->assertSame(0, Customer::count());
    }

    public function test_the_card_webhook_is_what_creates_the_customer(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        $this->post(route('signup.store'), $this->payload())->assertRedirect();

        $pending = PendingSignup::sole();
        $this->get(route('signup.card', ['pending' => $pending->token]))->assertOk();

        $this->assertSame('lp-1', $pending->fresh()->cardcom_lp_id);
        $this->assertSame(0, Customer::count());

        $this->deliverWebhook($pending);

        $customer = Customer::sole();
        $this->assertSame('new@example.co.il', $customer->email);
        $this->assertTrue($customer->hasActiveCard());
        $this->assertNotNull($pending->fresh()->completed_at);
    }

    public function test_the_same_session_announced_twice_opens_one_customer(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        $this->post(route('signup.store'), $this->payload())->assertRedirect();

        $pending = PendingSignup::sole();
        $this->get(route('signup.card', ['pending' => $pending->token]))->assertOk();

        // Cardcom announces a finished session twice — a webhook and a browser
        // redirect, in no guaranteed order. Both create the customer, so both
        // must be the same customer.
        $this->deliverWebhook($pending);
        $this->deliverWebhook($pending);
        $this->get(route('signup.done', ['pending' => $pending->token]))->assertOk();

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Customer::sole()->paymentTokens()->count());
    }

    public function test_a_refused_card_opens_nothing(): void
    {
        $this->cardcomReturns();
        $this->post(route('signup.store'), $this->payload())->assertRedirect();
        $pending = PendingSignup::sole();
        $this->get(route('signup.card', ['pending' => $pending->token]))->assertOk();

        // Cardcom says the session ended with no token.
        [$event] = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile', 'lp-declined', [
            'LowProfileId' => 'lp-1', 'ResponseCode' => 2, 'Description' => 'הכרטיס נדחה',
        ]);
        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $this->assertSame(0, Customer::count());
        $this->assertNull($pending->fresh()->completed_at);
    }

    public function test_returning_from_cardcom_without_a_token_does_not_open_a_customer(): void
    {
        $this->cardcomReturns();
        $this->post(route('signup.store'), $this->payload())->assertRedirect();
        $pending = PendingSignup::sole();
        $this->get(route('signup.card', ['pending' => $pending->token]))->assertOk();

        // A browser can arrive at the success URL by any route. Believing it
        // would open a customer on the strength of a URL — and the answer
        // Cardcom gives for this session carries no token.
        $this->get(route('signup.done', ['pending' => $pending->token]))
            ->assertOk()
            ->assertSee('מאמתים', false);

        $this->assertSame(0, Customer::count());
    }

    public function test_a_cardcom_outage_stops_the_signup_instead_of_opening_a_customer(): void
    {
        // The card provider is unreachable.
        $this->cardcomReturns(['ResponseCode' => 1, 'Description' => 'error'], 500);
        $this->post(route('signup.store'), $this->payload())->assertRedirect();

        // The card provider is unreachable. A card is required to open a
        // customer, so the signup stops — the cost of the guarantee, and the
        // customer is told plainly rather than left believing they registered.
        Http::fake(['*' => Http::response(['ResponseCode' => 1, 'Description' => 'error'], 500)]);

        $this->get(route('signup.card', ['pending' => PendingSignup::sole()->token]))
            ->assertOk()
            ->assertSee('לא ניתן להשלים את ההרשמה כרגע', false);

        $this->assertSame(0, Customer::count());
    }

    public function test_an_expired_pending_signup_cannot_be_resumed(): void
    {
        $this->cardcomReturns();
        config(['billing.signup.pending_lifetime_hours' => 72]);

        $this->post(route('signup.store'), $this->payload())->assertRedirect();
        $pending = PendingSignup::sole();

        $this->travel(4)->days();

        $this->get(route('signup.card', ['pending' => $pending->token]))
            ->assertOk()
            ->assertSee('הקישור אינו פעיל', false);
    }

    public function test_an_abandoned_signup_and_its_signature_are_pruned(): void
    {
        $this->cardcomReturns();
        config(['billing.signup.pending_lifetime_hours' => 72]);

        $this->post(route('signup.store'), $this->payload())->assertRedirect();
        $path = PendingSignup::sole()->signature_path;
        Storage::disk('local')->assertExists($path);

        $this->travel(4)->days();
        (new PrunePendingSignupsJob)->handle();

        // A name, a phone, an email and a drawn signature belonging to somebody
        // who is not a customer and never became one.
        $this->assertSame(0, PendingSignup::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_completed_signup_is_never_pruned(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        config(['billing.signup.pending_lifetime_hours' => 72]);

        $this->post(route('signup.store'), $this->payload())->assertRedirect();
        $pending = PendingSignup::sole();
        $this->get(route('signup.card', ['pending' => $pending->token]))->assertOk();
        $this->deliverWebhook($pending);

        $this->travel(30)->days();
        (new PrunePendingSignupsJob)->handle();

        // It is the record of how an existing customer came to be.
        $this->assertSame(1, PendingSignup::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_a_manager_can_waive_the_card_for_one_prospect(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        $manager = User::factory()->create(['name' => 'רונית']);
        $invite = SignupInvite::factory()->cardExempt('סוכן משנה בהסכם מיוחד')->create(['created_by' => $manager->id]);

        $this->post(route('signup.store'), $this->payload(['invite' => $invite->token]))
            ->assertRedirectContains('/join/done/');

        $customer = Customer::sole();
        $this->assertFalse($customer->hasActiveCard());
        // Who decided, and why — the line that explains it on the day a payment
        // does not arrive and there is nothing to fall back on.
        $this->assertNotNull($customer->card_exempt_at);
        $this->assertSame('סוכן משנה בהסכם מיוחד', $customer->card_exempt_reason);
        $this->assertSame($manager->id, $customer->card_exempt_by);
    }

    public function test_an_exempt_customer_is_not_chased_for_a_card(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        $invite = SignupInvite::factory()->cardExempt()->create();

        $this->post(route('signup.store'), $this->payload(['invite' => $invite->token, 'payment_method' => 'bank_transfer']))
            ->assertRedirect();

        // Nagging them would be the system arguing with a decision a person
        // already made and wrote down.
        $this->assertSame(0, Customer::query()->missingSecurityCard()->count());
    }

    public function test_an_exemption_is_spent_the_first_time_it_is_used(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        $invite = SignupInvite::factory()->cardExempt()->create();

        $this->post(route('signup.store'), $this->payload(['invite' => $invite->token]))->assertRedirect();
        $this->assertNotNull($invite->fresh()->used_at);
        $this->assertFalse($invite->fresh()->waivesCard());

        // Forwarded on to somebody else, it is an ordinary signup link again —
        // one waiver must not become a standing way in.
        $this->post(route('signup.store'), $this->payload([
            'invite' => $invite->token,
            'email' => 'second@example.co.il',
            'phone' => '0529876543',
        ]))->assertRedirectContains('/join/card/');

        $this->assertSame(1, Customer::count());
    }

    public function test_an_expired_invite_waives_nothing(): void
    {
        $this->cardcomReturns();
        $invite = SignupInvite::factory()->cardExempt()->create(['expires_at' => now()->subDay()]);

        $this->post(route('signup.store'), $this->payload(['invite' => $invite->token]))
            ->assertRedirectContains('/join/card/');

        $this->assertSame(0, Customer::count());
    }

    public function test_an_invented_invite_token_waives_nothing(): void
    {
        $this->cardcomReturns();
        // The field names an invite; it does not grant one.
        $this->post(route('signup.store'), $this->payload(['invite' => str_repeat('a', 48)]))
            ->assertRedirectContains('/join/card/');

        $this->assertSame(0, Customer::count());
    }

    public function test_an_ordinary_invite_still_requires_a_card(): void
    {
        $this->cardcomReturns();
        $invite = SignupInvite::factory()->create(); // card_exempt defaults to false

        $this->post(route('signup.store'), $this->payload(['invite' => $invite->token]))
            ->assertRedirectContains('/join/card/');

        $this->assertSame(0, Customer::count());
    }

    public function test_an_exempt_signup_never_claims_a_card_will_be_taken(): void
    {
        $this->cardcomReturns();
        config(['billing.card_fallback_days' => 30]);
        $invite = SignupInvite::factory()->cardExempt()->create();

        // The terms the customer ticks must say what is actually true for them:
        // no card is taken here, so none may be promised.
        $this->get(route('signup', ['invite' => $invite->token]))
            ->assertOk()
            ->assertDontSee('כמו כן אני מאשר/ת כי כרטיס האשראי שיימסר משמש כביטחון', false);

        $this->get(route('signup'))
            ->assertOk()
            ->assertSee('כמו כן אני מאשר/ת כי כרטיס האשראי שיימסר משמש כביטחון', false);
    }

    public function test_an_exempt_signup_records_no_consent_it_never_asked_for(): void
    {
        $this->cardcomReturns();
        Queue::fake();
        config(['billing.card_fallback_days' => 30]);
        $invite = SignupInvite::factory()->cardExempt()->create();

        $this->post(route('signup.store'), $this->payload(['invite' => $invite->token]))->assertRedirect();

        // Nobody showed them the security-card clause, so stamping the consent
        // would manufacture one — and the chase reads that stamp as agreement.
        $this->assertNull(Customer::sole()->security_card_terms_at);
    }

    /** Cardcom announcing a captured card for this signup. */
    private function deliverWebhook(PendingSignup $pending): void
    {
        [$event] = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile', 'lp-'.$pending->id.'-'.uniqid(), [
            'LowProfileId' => $pending->fresh()->cardcom_lp_id,
            'ResponseCode' => 0,
            'TokenInfo' => ['Token' => 'tok-1', 'CardMonth' => 12, 'CardYear' => (int) now()->addYears(3)->format('Y')],
            'TranzactionInfo' => ['Last4CardDigits' => '4580', 'CardName' => 'Visa'],
        ]);

        (new ProcessCardcomLowProfileJob($event->id))->handle();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'עסק חדש בע״מ',
            'contact_name' => 'ישראל ישראלי',
            'business_number' => '512345678',
            'business_type' => 'licensed_dealer',
            'email' => 'new@example.co.il',
            'phone' => '0501234567',
            'domain' => 'https://newbiz.co.il',
            'payment_method' => 'credit_card',
            'terms' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('signature'),
        ], $overrides);
    }
}
