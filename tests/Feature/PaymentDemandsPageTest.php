<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Filament\Pages\PaymentDemands;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\ProcessManualChargeJob;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "דרישות תשלום" screen: open a proforma demand (which issues the proforma
 * and emails a non-auto-charging link + bank transfer) and list existing demands.
 */
class PaymentDemandsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_demand_dispatches_a_proforma_payment_link_with_transfer(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'customer_id' => $customer->id,
                'description' => 'אחסון שנתי',
                'items' => [],
                'amount' => 250,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, function (SendPaymentLinkJob $job) use ($customer): bool {
            return $job->customerId === $customer->id
                && $job->totalAgorot === 25000
                && $job->channel === 'email'
                // A demand never auto-charges: bank transfer (preferred) is
                // listed first, the manual card link second.
                && $job->methods === ['transfer', 'link'];
        });
    }

    public function test_a_demand_carries_the_chosen_pay_by_date(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'customer_id' => $customer->id,
                'description' => 'אחסון שנתי',
                'items' => [],
                'amount' => 250,
                'due_at' => now()->addDays(10)->toDateString(),
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, fn (SendPaymentLinkJob $job): bool => $job->dueAt === now()->addDays(10)->toDateString());
    }

    public function test_items_are_entered_net_and_vat_is_added_per_item(): void
    {
        Queue::fake();
        config(['billing.vat_rate' => 0.18]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'customer_id' => $customer->id,
                'description' => 'חבילה',
                'items' => [
                    // Net 100 + VAT → 118.
                    ['name' => 'אחסון', 'qty' => 1, 'unit_price' => 100, 'add_vat' => true],
                    // Net 50, no VAT, ×2 → 100.
                    ['name' => 'שירות פטור', 'qty' => 2, 'unit_price' => 50, 'add_vat' => false],
                ],
                'amount' => null,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, function (SendPaymentLinkJob $job): bool {
            return $job->totalAgorot === 21800 // 11800 + (5000 × 2)
                && $job->lines[0]['unit_price_agorot'] === 11800
                && $job->lines[1]['unit_price_agorot'] === 5000;
        });
    }

    /**
     * דרישה ללקוח שעדיין אינו במערכת — נפתח כרטיס ונשלחת הדרישה, בפעולה אחת.
     *
     * מי שנאלץ לעצור, לעבור למסך לקוחות ולחזור, שולח בסוף בקשת תשלום בוואטסאפ
     * בלי חשבונית עסקה ובלי מעקב.
     */
    public function test_a_demand_can_open_the_customer_it_is_addressed_to(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'new_customer' => true,
                'new_name' => 'עסק חדש בע״מ',
                'new_email' => 'new@example.co.il',
                'new_business_number' => '515151515',
                'description' => 'בניית אתר',
                'items' => [],
                'amount' => 1000,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        $customer = Customer::where('email', 'new@example.co.il')->first();

        $this->assertNotNull($customer);
        $this->assertSame('עסק חדש בע״מ', $customer->name);
        $this->assertSame('515151515', $customer->business_number);

        Queue::assertPushed(SendPaymentLinkJob::class,
            fn (SendPaymentLinkJob $job): bool => $job->customerId === $customer->id && $job->totalAgorot === 100000);
    }

    /**
     * כתובת מייל שכבר במערכת מחזירה את הלקוח הקיים.
     *
     * כפילות בכרטיס לקוח היא כפילות בחיובים ובחשבוניות — ומי שממלא את הטופס
     * אינו יודע, ואינו אמור לדעת, שהלקוח כבר שם.
     */
    public function test_a_known_email_reuses_the_customer_instead_of_duplicating_it(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $existing = Customer::factory()->create(['email' => 'known@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'new_customer' => true,
                'new_name' => 'שם אחר לגמרי',
                'new_email' => 'known@example.co.il',
                'description' => 'תשלום',
                'items' => [],
                'amount' => 100,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Customer::where('email', 'known@example.co.il')->count());
        Queue::assertPushed(SendPaymentLinkJob::class,
            fn (SendPaymentLinkJob $job): bool => $job->customerId === $existing->id);
    }

    /** סכום לא תקין לא פותח לקוח שאיש לא ביקש. */
    public function test_an_invalid_amount_does_not_leave_a_customer_behind(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'new_customer' => true,
                'new_name' => 'עסק חדש',
                'new_email' => 'zero@example.co.il',
                'description' => 'תשלום',
                'items' => [],
                'amount' => 0,
                'channel' => 'email',
            ]);

        $this->assertSame(0, Customer::where('email', 'zero@example.co.il')->count());
        Queue::assertNotPushed(SendPaymentLinkJob::class);
    }

    public function test_the_table_lists_only_sent_demands(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();

        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);
        // An ordinary (non-demand) charge must not appear.
        $plain = $this->charge($customer->id, ['demand_sent_at' => null]);

        Livewire::test(PaymentDemands::class)
            ->assertCanSeeTableRecords([$demand])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_mark_paid_finalises_the_demand_and_issues_the_tax_receipt(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('markPaid', $demand)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ChargeStatus::Succeeded, $demand->fresh()->status);
        $this->assertNotNull($demand->fresh()->charged_at);
        Queue::assertPushed(IssueInvoiceJob::class,
            fn (IssueInvoiceJob $job): bool => $job->chargeId === $demand->id);
    }

    /*
    |--------------------------------------------------------------------------
    | חיוב מהכרטיס השמור
    |--------------------------------------------------------------------------
    |
    | דרישת תשלום לעולם אינה גובה מעצמה — היא מבקשת מהלקוח לשלם וממתינה. הכפתור
    | הזה הוא העקיפה המכוונת לשיחה שנגמרת ב"פשוט תורידו מהכרטיס", ועד עכשיו
    | המשמעות שלה הייתה להקליד את החיוב מחדש במסך "חיוב ידני" — מה שמשאיר שתי
    | שורות על חוב אחד, ודרישה פתוחה שממשיכה לשלוח תזכורות.
    */

    public function test_the_saved_card_can_be_charged_straight_from_the_demand(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('chargeSavedCard', $demand)
            ->assertHasNoTableActionErrors();

        // The charge runs through the same job as every other one-off charge —
        // per-charge lock, pending-only, every Cardcom response written to the
        // row, invoice on success — rather than a second money path of its own.
        Queue::assertPushed(ProcessManualChargeJob::class,
            fn (ProcessManualChargeJob $job): bool => $job->chargeId === $demand->id);
    }

    /** בלי כרטיס שמור אין מה להציע. */
    public function test_the_button_is_not_offered_without_a_saved_card(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->assertTableActionHidden('chargeSavedCard', $demand);
    }

    /**
     * כרטיס שהוחלף אינו כרטיס שמור.
     *
     * זה בדיוק המצב שבו לקוח כבר תיקן את הכרטיס אצלנו: חיוב הישן היה נדחה,
     * והכפתור היה מבטיח משהו שאינו יכול לקרות.
     */
    public function test_a_replaced_card_does_not_count_as_a_saved_one(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Replaced]);
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->assertTableActionHidden('chargeSavedCard', $demand);
    }

    /** דרישה ששולמה כבר אינה ניתנת לחיוב — אין דרך חזרה מחיוב כפול. */
    public function test_a_demand_already_paid_cannot_be_charged_again(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $demand = $this->charge($customer->id, [
            'demand_sent_at' => now(),
            'demand_channel' => 'email',
            'status' => ChargeStatus::Succeeded,
        ]);

        Livewire::test(PaymentDemands::class)
            ->assertTableActionHidden('chargeSavedCard', $demand);
    }

    /**
     * הכרטיס הוסר בזמן שהמסך היה פתוח, והשורה שעל המסך עוד לא יודעת.
     *
     * הדגל שמחליט אם הכפתור מוצג נטען עם העמוד, ולכן הוא יכול להיות ישן. מה
     * שנבדק כאן הוא התוצאה ולא השכבה שעצרה אותה: הדרישה נשארת בדיוק כפי שהייתה,
     * ושום חיוב לא יוצא. (בפועל עוצרת אותה בדיקת הנראות, שמורצת מחדש מול השורה
     * העדכנית; הבדיקה בתוך הפעולה עצמה היא השכבה שמאחוריה.)
     */
    public function test_a_card_removed_since_the_page_loaded_does_not_get_charged(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        $page = Livewire::test(PaymentDemands::class);

        // The row still carries the flag computed while a card existed.
        $demand->setAttribute('customer_has_card', true);
        $token->update(['status' => TokenStatus::Replaced]);

        $page->callTableAction('chargeSavedCard', $demand);

        Queue::assertNotPushed(ProcessManualChargeJob::class);
        $this->assertSame(ChargeStatus::Pending, $demand->fresh()->status);
    }

    /**
     * מקצה לקצה: הכפתור, החיוב בפועל, וחשבונית המס/קבלה.
     *
     * הבדיקות למעלה עוצרות בשליחת העבודה לתור. זו מריצה אותה, כדי שמה שנבדק
     * יהיה מה שקורה ללקוח ולא מה שנרשם בתור.
     */
    public function test_charging_the_saved_card_closes_the_demand_and_issues_the_tax_receipt(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Http::fake(['*/Transactions/Transaction' => Http::response(['ResponseCode' => 0, 'TranzactionId' => 4242])]);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('chargeSavedCard', $demand)
            ->assertHasNoTableActionErrors();

        (new ProcessManualChargeJob($demand->id))->handle(app(CardcomClient::class));

        $demand->refresh();
        $this->assertSame(ChargeStatus::Succeeded, $demand->status);
        $this->assertNotNull($demand->charged_at);
        // Architecture rule #6: the Cardcom answer is on the row either way.
        $this->assertSame('4242', $demand->cardcom_transaction_id);
        Bus::assertDispatched(IssueInvoiceJob::class,
            fn (IssueInvoiceJob $job): bool => $job->chargeId === $demand->id);
    }

    /**
     * חיוב שנדחה משאיר את הסיבה על השורה, ואינו מנפיק חשבונית.
     *
     * כלל הארכיטקטורה: חשבונית מונפקת רק אחרי חיוב שהצליח.
     */
    public function test_a_declined_card_leaves_the_reason_on_the_row_and_issues_nothing(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);

        $customer = Customer::factory()->create();
        PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Http::fake(['*/Transactions/Transaction' => Http::response([
            'ResponseCode' => 33, 'Description' => 'כרטיס חסום',
        ])]);

        (new ProcessManualChargeJob($demand->id))->handle(app(CardcomClient::class));

        $demand->refresh();
        $this->assertSame(ChargeStatus::Failed, $demand->status);
        $this->assertSame('33', $demand->cardcom_response_code);
        $this->assertNotNull($demand->failure_reason);
        Bus::assertNotDispatched(IssueInvoiceJob::class);
    }

    /**
     * כרטיס שפג תוקפו עדיין רשום "פעיל", ואסור לגבות ממנו.
     *
     * שום דבר במערכת אינו עובר על הטבלה ומסמן כרטיסים כפגי תוקף, ולכן status
     * לבדו אינו השאלה. חיוב כזה נדחה בבנק, מסמן את הדרישה כ"נכשלה" ומוציא
     * אותה מזרם הגבייה — בגלל כרטיס שאיש מעולם לא ניסה לתקן.
     */
    public function test_an_expired_card_is_not_treated_as_chargeable(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        PaymentToken::factory()->create([
            'customer_id' => $customer->id,
            'status' => TokenStatus::Active,
            'expiry_month' => 1,
            'expiry_year' => (int) now()->subYear()->format('Y'),
        ]);
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        $this->assertNull($demand->chargeableToken());

        Livewire::test(PaymentDemands::class)
            ->assertTableActionHidden('chargeSavedCard', $demand);
    }

    /** ...וכרטיס ברירת המחדל שפג תוקפו אינו גובר על כרטיס תקף אחר. */
    public function test_an_expired_default_card_gives_way_to_a_live_one(): void
    {
        $customer = Customer::factory()->create();

        $expired = PaymentToken::factory()->create([
            'customer_id' => $customer->id,
            'expiry_month' => 1,
            'expiry_year' => (int) now()->subYear()->format('Y'),
        ]);
        $live = PaymentToken::factory()->create([
            'customer_id' => $customer->id,
            'expiry_month' => 12,
            'expiry_year' => (int) now()->addYears(3)->format('Y'),
        ]);

        $customer->update(['default_token_id' => $expired->id]);

        $demand = $this->charge($customer->id, ['demand_sent_at' => now()]);

        $this->assertSame($live->id, $demand->fresh()->chargeableToken()?->id);
    }

    /**
     * דרישה שתלויה במנוי מוצאת את הכרטיס של הלקוח שמאחוריו.
     *
     * חיוב יכול לשאת את הלקוח ישירות או דרך המנוי, ומנפיק החשבוניות קורא אותו
     * דרך resolveCustomer(). כשהעבודה שגובה קראה רק את customer הישיר, לקוח עם
     * כרטיס תקף בהחלט היה מקבל "אין ללקוח כרטיס פעיל שמור".
     */
    public function test_a_demand_that_hangs_off_a_subscription_finds_the_card_behind_it(): void
    {
        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'token_id' => $token->id,
        ]);

        $demand = $this->charge($customer->id, [
            'customer_id' => null,
            'subscription_id' => $subscription->id,
            'demand_sent_at' => now(),
        ]);

        $this->assertSame($token->id, $demand->chargeableToken()?->id);
    }

    public function test_toggle_reminders_pauses_and_resumes_a_single_demand(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('toggleReminders', $demand)
            ->assertHasNoTableActionErrors();
        $this->assertTrue($demand->fresh()->demand_reminders_paused);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('toggleReminders', $demand)
            ->assertHasNoTableActionErrors();
        $this->assertFalse($demand->fresh()->demand_reminders_paused);
    }

    private function charge(int $customerId, array $overrides = []): Charge
    {
        return Charge::create(array_merge([
            'subscription_id' => null,
            'customer_id' => $customerId,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'דרישה',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ], $overrides));
    }
}
