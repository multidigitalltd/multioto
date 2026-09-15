<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\TokenStatus;
use App\Jobs\ChaseMissingSecurityCardJob;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The security card that signup asked for and never got.
 *
 * The card page is the LAST step of /join and the customer row is committed
 * before it opens — so a customer who closes the tab leaves a record that looks
 * exactly like a finished signup. Nothing noticed: the missing-card chase runs
 * off subscriptions whose charge date has passed, and these customers have no
 * subscription for weeks and then a manually-collected one that scope skips on
 * purpose. The arrangement promised a collection with nothing behind it.
 */
class MissingSecurityCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(['*' => Http::response(['result' => true])]);
    }

    public function test_a_customer_who_left_before_the_card_page_is_found(): void
    {
        $abandoned = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(3),
        ]);

        // No subscription at all — the team sets those up afterwards. This is
        // the window in which the customer was previously invisible.
        $this->assertSame(0, $abandoned->subscriptions()->count());
        $this->assertTrue(Customer::query()->missingSecurityCard()->whereKey($abandoned->id)->exists());
        $this->assertFalse($abandoned->hasActiveCard());
    }

    public function test_a_customer_who_gave_a_card_is_not_on_the_list(): void
    {
        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(3),
        ]);
        PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Active]);

        $this->assertTrue($customer->hasActiveCard());
        $this->assertSame(0, Customer::query()->missingSecurityCard()->count());
    }

    public function test_a_card_that_cannot_be_charged_does_not_count_as_a_card(): void
    {
        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(3),
        ]);

        // Expired, replaced, removed by hand: the row is still there and
        // default_token_id may still point at it. None of them can be charged,
        // and reading any of them as coverage is how a customer with nothing
        // usable reads as covered.
        foreach ([TokenStatus::Expired, TokenStatus::Replaced, TokenStatus::Removed] as $status) {
            PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => $status]);
        }

        $this->assertFalse($customer->hasActiveCard());
        $this->assertTrue(Customer::query()->missingSecurityCard()->whereKey($customer->id)->exists());
    }

    public function test_a_legacy_customer_is_not_chased_for_something_nobody_showed_them(): void
    {
        // Signed up years ago; the security-card clause did not exist.
        $legacy = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => null,
        ]);

        $this->assertSame(0, Customer::query()->missingSecurityCard()->count());

        (new ChaseMissingSecurityCardJob)->handle(
            app(CardCaptureLinkSender::class),
            app(TeamNotifier::class),
        );

        $this->assertSame(0, NotificationLog::query()->where('customer_id', $legacy->id)->count());
    }

    public function test_the_customer_is_asked_to_finish_the_step_they_left(): void
    {
        config(['billing.cards.security_missing.grace_hours' => 24]);

        $customer = Customer::factory()->create([
            'name' => 'עסק בע״מ',
            'email' => 'owner@example.com',
            'phone' => '0501234567',
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(2),
        ]);

        $this->runChase();

        $log = NotificationLog::query()
            ->where('customer_id', $customer->id)
            ->where('type', NotificationType::CardLink)
            ->first();

        $this->assertNotNull($log, 'the customer was never asked');
        // None of their money is late and nothing failed. A message that said
        // otherwise would be a debt claim against somebody who owes nothing.
        $this->assertStringContainsString('לביטחון', $log->body);
        $this->assertStringContainsString('העברה בנקאית', $log->body);
        $this->assertStringNotContainsString('לא הצלחנו לחייב', $log->body);
    }

    public function test_somebody_who_just_signed_up_is_left_alone(): void
    {
        config(['billing.cards.security_missing.grace_hours' => 24]);

        // They may still have the card page open in the next tab.
        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subHours(2),
        ]);

        $this->runChase();

        $this->assertSame(0, NotificationLog::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_customer_asked_recently_is_not_asked_again_today(): void
    {
        config([
            'billing.cards.security_missing.grace_hours' => 1,
            'billing.cards.security_missing.interval_days' => 4,
        ]);

        $customer = Customer::factory()->create([
            'payment_method' => 'standing_order',
            'security_card_terms_at' => now()->subDays(10),
        ]);

        $this->runChase();
        $this->assertSame(1, $this->timesAsked($customer));

        // Running again tomorrow must not produce a second message.
        $this->runChase();
        $this->assertSame(1, $this->timesAsked($customer));
    }

    public function test_a_card_link_sent_by_anyone_else_counts_as_having_asked(): void
    {
        config([
            'billing.cards.security_missing.grace_hours' => 1,
            'billing.cards.security_missing.interval_days' => 4,
        ]);

        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(10),
        ]);

        // The team pressed "קישור לכרטיס" this morning. Asking again tonight
        // because the sender was a different one is the machine losing count of
        // who it is talking to.
        NotificationLog::record('email', NotificationType::CardLink, $customer->email, 'נושא', 'גוף', $customer->id);

        $this->runChase();

        $this->assertSame(1, $this->timesAsked($customer));
    }

    public function test_asking_stops_at_the_cap(): void
    {
        config([
            'billing.cards.security_missing.grace_hours' => 1,
            'billing.cards.security_missing.interval_days' => 4,
            'billing.cards.security_missing.max_requests' => 2,
        ]);

        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(30),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->travel(5)->days();
            $this->runChase();
        }

        // A customer who ignored two requests will not be persuaded by a fifth.
        $this->assertSame(2, $this->timesAsked($customer));
    }

    public function test_the_chase_can_be_switched_off(): void
    {
        config(['billing.cards.security_missing.max_requests' => 0]);

        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(10),
        ]);

        $this->runChase();

        $this->assertSame(0, $this->timesAsked($customer));
    }

    public function test_a_card_that_arrives_ends_the_chase(): void
    {
        config([
            'billing.cards.security_missing.grace_hours' => 1,
            'billing.cards.security_missing.interval_days' => 1,
        ]);

        $customer = Customer::factory()->create([
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(10),
        ]);

        $this->runChase();
        $this->assertSame(1, $this->timesAsked($customer));

        PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Active]);

        $this->travel(5)->days();
        $this->runChase();

        $this->assertSame(1, $this->timesAsked($customer));
    }

    public function test_the_integrity_report_names_a_fallback_with_nothing_behind_it(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'לקוח ללא כרטיס',
            'payment_method' => 'bank_transfer',
            'security_card_terms_at' => now()->subDays(40),
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'card_fallback_days' => 30,
            // The whole point of this case: the arrangement exists, the card
            // does not.
            'token_id' => null,
        ]);

        // The customer was told their card would be charged if the transfer did
        // not arrive. It cannot be: there is no card. Nothing fails loudly,
        // because nothing is attempted.
        $found = Subscription::query()
            ->whereNotNull('card_fallback_days')
            ->whereHas('customer', fn ($q) => $q->missingSecurityCard())
            ->count();

        $this->assertSame(1, $found);
    }

    private function runChase(): void
    {
        (new ChaseMissingSecurityCardJob)->handle(
            app(CardCaptureLinkSender::class),
            app(TeamNotifier::class),
        );
    }

    /**
     * How many times this customer was ASKED — not how many messages went out.
     * One request reaches them on WhatsApp and email both.
     */
    private function timesAsked(Customer $customer): int
    {
        return NotificationLog::query()
            ->where('customer_id', $customer->id)
            ->where('type', NotificationType::CardLink)
            ->where('status', 'sent')
            ->pluck('sent_at')
            ->map(fn ($at): string => $at->toDateString())
            ->unique()
            ->count();
    }
}
