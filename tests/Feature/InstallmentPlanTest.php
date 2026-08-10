<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Filament\Pages\ManualCharge;
use App\Filament\Resources\SubscriptionResource\Pages\CreateSubscription;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendDunningNotificationJob;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use App\Support\InstallmentSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * פריסת תשלומים — מנוי שיש לו סוף.
 *
 * חוב של 7,000 ₪ שנפרס ל-14 תשלומים נראה בדיוק כמו מנוי: אותו סכום, אותו
 * תאריך, אותה גבייה ואותו דאנינג. ההבדל היחיד — שיש לו סוף — הוא כל מה שנבדק
 * כאן, כי הוא גם היחיד שיכול לעלות כסף: תשלום חמישה-עשר בפריסה של ארבעה-עשר
 * הוא כסף שנלקח ממי שאינו חייב אותו, והוא מתגלה אצל הלקוח ולא אצלנו.
 */
class InstallmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCardcom(bool $success = true, int $times = 1): void
    {
        $this->mock(CardcomClient::class, function ($mock) use ($success, $times) {
            $mock->shouldReceive('chargeToken')->times($times)->andReturn(new ChargeResult(
                success: $success,
                transactionId: $success ? 'tx-1' : null,
                responseCode: $success ? '0' : '33',
                message: $success ? null : 'Refused',
            ));
        });
    }

    /**
     * גביית תשלום אחד, כפי שהיא קורית בפועל: חיוב, ואז השעון מגיע לתאריך הבא.
     *
     * דחיפת התאריך אחורה במקום הזזת השעון הייתה גובה שוב את אותה תקופה — כלומר
     * תשלום אחד, לא שניים, וזו בדיוק ההבחנה שהפיצ'ר הזה עומד עליה.
     */
    private function collect(Subscription $plan): void
    {
        ChargeSubscriptionJob::dispatchSync($plan->id);

        $plan->refresh();

        if ($plan->next_charge_at !== null) {
            $this->travelTo($plan->next_charge_at->copy()->addHour());
        }
    }

    /** פריסה של ₪500 ל-3 תשלומים, בלי מע״מ כדי שהמספרים יהיו קריאים. */
    private function plan(int $installments = 3): Subscription
    {
        return Subscription::factory()->create([
            'plan_id' => null,
            'name' => 'פריסת חוב',
            'price_agorot_override' => 50000,
            'vat_applies' => false,
            'installments_total' => $installments,
            'next_charge_at' => now()->subHour(),
            'customer_id' => Customer::factory()->create(['vat_exempt' => true])->id,
        ]);
    }

    /*
    | ----------------------------------------------------------------
    | החשבון
    | ----------------------------------------------------------------
    */

    /** הסכומים: כמה בסך הכל, כמה נותר. */
    public function test_the_plan_knows_its_total_and_what_is_left(): void
    {
        $plan = $this->plan(14);

        $this->assertTrue($plan->isInstallmentPlan());
        $this->assertSame(14, $plan->installmentsRemaining());
        $this->assertSame(700000, $plan->installmentsTotalAgorot());   // ₪7,000
        $this->assertSame(700000, $plan->installmentsRemainingAgorot());
    }

    /** מנוי רגיל אינו פריסה ואינו נסגר לעולם. */
    public function test_an_ordinary_subscription_is_not_a_plan(): void
    {
        $subscription = Subscription::factory()->create(['installments_total' => null]);

        $this->assertFalse($subscription->isInstallmentPlan());
        $this->assertFalse($subscription->installmentPlanComplete());
        $this->assertSame(0, $subscription->installmentsRemaining());
    }

    /*
    | ----------------------------------------------------------------
    | הגבייה
    | ----------------------------------------------------------------
    */

    /** תשלום שנגבה מקדם את המונה, והמנוי ממשיך לתשלום הבא. */
    public function test_a_collected_instalment_advances_the_plan(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true);

        $plan = $this->plan(3);

        ChargeSubscriptionJob::dispatchSync($plan->id);

        $plan->refresh();
        $this->assertSame(1, $plan->installmentsPaid());
        $this->assertSame(2, $plan->installmentsRemaining());
        $this->assertSame(100000, $plan->installmentsRemainingAgorot());
        $this->assertSame(SubscriptionStatus::Active, $plan->status);
        $this->assertNotNull($plan->next_charge_at);
    }

    /**
     * התשלום האחרון סוגר את הפריסה.
     *
     * גם הסטטוס וגם תאריך החיוב הבא — הראשון כדי שיהיה גלוי, השני כדי שלא
     * יישאר דבר שמישהו יכול לגבות.
     */
    public function test_the_last_instalment_closes_the_plan(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true, times: 3);

        $plan = $this->plan(3);

        foreach (range(1, 3) as $i) {
            $this->collect($plan);
        }

        $plan->refresh();
        $this->assertSame(3, $plan->installmentsPaid());
        $this->assertSame(0, $plan->installmentsRemaining());
        $this->assertTrue($plan->installmentPlanComplete());
        $this->assertSame(SubscriptionStatus::Canceled, $plan->status);
        $this->assertNull($plan->next_charge_at);
        $this->assertNotNull($plan->canceled_at);
    }

    /**
     * ואי אפשר לגבות תשלום נוסף — גם לא ידנית.
     *
     * זו הבדיקה שכל הפיצ'ר קיים בשבילה. גם אם מישהו יחזיר תאריך חיוב ביד
     * וילחץ "חייב עכשיו", אין גבייה: הפריסה שולמה.
     */
    public function test_no_further_charge_is_possible_once_the_plan_is_paid(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true, times: 3);

        $plan = $this->plan(3);

        foreach (range(1, 3) as $i) {
            $this->collect($plan);
        }

        $this->assertFalse($plan->refresh()->isChargeable());

        // ניסיון גבייה נוסף, אחרי החזרת התאריך והסטטוס ביד.
        $plan->update(['status' => SubscriptionStatus::Active, 'next_charge_at' => now()->subHour()]);
        ChargeSubscriptionJob::dispatchSync($plan->id);

        // עדיין שלושה חיובים, לא ארבעה. (המוק מצפה לשלוש קריאות בדיוק.)
        $this->assertSame(3, $plan->charges()->where('status', ChargeStatus::Succeeded)->count());
    }

    /**
     * חיוב שנכשל אינו מקדם את המונה.
     *
     * 14 תשלומים ייגבו — לא 13 עם אחד שנכשל באמצע וספר את עצמו.
     */
    public function test_a_failed_charge_does_not_count_as_an_instalment(): void
    {
        Queue::fake([IssueInvoiceJob::class, SendDunningNotificationJob::class]);
        $this->fakeCardcom(success: false);

        $plan = $this->plan(3);

        ChargeSubscriptionJob::dispatchSync($plan->id);

        $plan->refresh();
        $this->assertSame(1, $plan->charges()->where('status', ChargeStatus::Failed)->count());
        $this->assertSame(0, $plan->installmentsPaid());
        $this->assertSame(3, $plan->installmentsRemaining());
        $this->assertFalse($plan->installmentPlanComplete());
    }

    /**
     * ניסיון חוזר שהצליח על אותה תקופה נספר פעם אחת.
     *
     * הדאנינג גובה שוב את אותה תקופה, ולכן הספירה היא לפי תקופות ולא לפי שורות
     * חיוב — אחרת חודש שנגבה בניסיון השני היה "אוכל" שני תשלומים מהפריסה.
     */
    public function test_a_retry_of_the_same_period_counts_once(): void
    {
        $plan = $this->plan(3);

        $period = now()->startOfDay();

        foreach ([ChargeStatus::Failed, ChargeStatus::Succeeded] as $i => $status) {
            $plan->charges()->create([
                'amount_agorot' => 50000, 'vat_agorot' => 0, 'total_agorot' => 50000,
                'currency' => 'ILS', 'status' => $status, 'attempt_number' => $i + 1,
                'period_start' => $period, 'period_end' => $period->copy()->addMonth(),
                'charged_at' => $status === ChargeStatus::Succeeded ? now() : null,
            ]);
        }

        $this->assertSame(1, $plan->installmentsPaid());
        $this->assertSame(2, $plan->installmentsRemaining());
    }

    /** גם תשלום שנגבה בהעברה בנקאית ונרשם ביד סוגר את הפריסה בסופה. */
    public function test_a_plan_collected_by_hand_also_closes_itself(): void
    {
        Queue::fake([IssueInvoiceJob::class]);

        $plan = $this->plan(2);
        $plan->update(['token_id' => null]);

        $service = app(SubscriptionCollectionService::class);

        $service->recordPayment($plan->refresh());
        $this->assertSame(SubscriptionStatus::Active, $plan->refresh()->status);

        $this->travelTo($plan->refresh()->next_charge_at->copy()->addHour());
        $service->recordPayment($plan->refresh());

        $plan->refresh();
        $this->assertSame(2, $plan->installmentsPaid());
        $this->assertSame(SubscriptionStatus::Canceled, $plan->status);
        $this->assertNull($plan->next_charge_at);
    }

    /*
    | ----------------------------------------------------------------
    | חלוקת סכום כולל
    | ----------------------------------------------------------------
    */

    /** 7,000 ₪ ל-14 תשלומים, כשהסכום כולל מע״מ — ₪500 בחודש. */
    public function test_a_total_is_split_into_a_monthly_amount(): void
    {
        $split = InstallmentSplit::compute(700000, 14, 0.18, totalIncludesVat: true);

        $this->assertSame(50000, $split['per_charge_agorot']);
        $this->assertSame(700000, $split['collected_agorot']);
        $this->assertSame(0, $split['difference_agorot']);
    }

    /** בלי מע״מ (לקוח פטור) החלוקה היא פשוט הסכום חלקי המספר. */
    public function test_a_vat_free_total_divides_directly(): void
    {
        $split = InstallmentSplit::compute(700000, 14, 0.0, totalIncludesVat: true);

        $this->assertSame(50000, $split['base_agorot']);
        $this->assertSame(0, $split['vat_agorot']);
        $this->assertSame(700000, $split['collected_agorot']);
    }

    /**
     * סכום שאינו מתחלק — הפער נאמר, לא מוסתר.
     *
     * המנוי גובה את אותו סכום בכל חודש, ולכן חלוקה שאינה יוצאת עגולה תיגבה
     * בפועל סכום מעט שונה מזה שהוזן. מי שמזין חייב לראות את הסכום האמיתי.
     */
    public function test_a_total_that_does_not_divide_reports_the_gap(): void
    {
        $split = InstallmentSplit::compute(700000, 12, 0.0, totalIncludesVat: true);

        $this->assertNotSame(0, $split['difference_agorot']);
        $this->assertSame($split['per_charge_agorot'] * 12, $split['collected_agorot']);
        $this->assertStringContainsString('אינו מתחלק', InstallmentSplit::describe($split, 12));
    }

    /** קלט חסר מחזיר אפסים ולא מחלק באפס. */
    public function test_nonsense_input_computes_nothing(): void
    {
        $this->assertSame(0, InstallmentSplit::compute(700000, 0, 0.18, true)['per_charge_agorot']);
        $this->assertSame(0, InstallmentSplit::compute(0, 12, 0.18, true)['per_charge_agorot']);
        $this->assertSame('—', InstallmentSplit::describe(InstallmentSplit::compute(0, 0, 0.18, true), 0));
    }

    /*
    | ----------------------------------------------------------------
    | סגירה בלי תשלום
    | ----------------------------------------------------------------
    */

    /**
     * הורדת מספר התשלומים למה שכבר שולם סוגרת את הפריסה מיד.
     *
     * בלי זה המנוי היה נשאר פעיל עם תאריך חיוב, בלתי ניתן לגבייה — המתזמן היה
     * מרים אותו כל רבע שעה לנצח, ובדיקת תקינות הכספים הייתה מדווחת עליו כפיגור
     * שלעולם לא ייפתר.
     */
    public function test_saving_a_count_that_is_already_reached_closes_the_plan(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true);

        $plan = $this->plan(5);
        ChargeSubscriptionJob::dispatchSync($plan->id);

        $plan->refresh()->update(['installments_total' => 1]);

        $plan->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $plan->status);
        $this->assertNull($plan->next_charge_at);
    }

    /**
     * לחיצה שנייה על "סמן כשולם" בתשלום האחרון אינה גובה עוד תשלום.
     *
     * סגירת הפריסה מוחקת את תאריך החיוב, ושומר הכפילות הקיים נשען על תאריך
     * עתידי — כך שבלי בדיקה מפורשת הקריאה השנייה הייתה פותחת תקופה חדשה מהיום,
     * רושמת תשלום נוסף ומנפיקה עליו חשבונית.
     */
    public function test_a_second_click_on_the_final_manual_payment_collects_nothing(): void
    {
        Queue::fake([IssueInvoiceJob::class]);

        $plan = $this->plan(1);
        $plan->update(['token_id' => null]);

        $service = app(SubscriptionCollectionService::class);

        $service->recordPayment($plan->refresh());
        $service->recordPayment($plan->refresh());

        $this->assertSame(1, $plan->charges()->where('status', ChargeStatus::Succeeded)->count());
        $this->assertSame(1, $plan->refresh()->installmentsPaid());
    }

    /*
    | ----------------------------------------------------------------
    | פריסה מתוך "חיוב ידני"
    | ----------------------------------------------------------------
    */

    /** סכום ומספר תשלומים בחיוב ידני פותחים מנוי פריסה וגובים את הראשון. */
    public function test_the_manual_charge_screen_opens_a_plan(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['vat_exempt' => true]);
        PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Active]);

        Livewire::test(ManualCharge::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 7000,
                'description' => 'פריסת חוב',
                'vat_exempt' => true,
                'installments' => 14,
            ])
            ->call('submit');

        $plan = Subscription::where('customer_id', $customer->id)->sole();

        $this->assertSame(14, $plan->installments_total);
        $this->assertSame(50000, $plan->price_agorot_override);   // ₪500 לחודש
        $this->assertSame(700000, $plan->installmentsTotalAgorot());

        Queue::assertPushed(ChargeSubscriptionJob::class);
    }

    /**
     * בלי כרטיס שמור אין פריסה — ונאמר למה.
     *
     * עמוד התשלום של קארדקום גובה פעם אחת ואינו שומר כרטיס, כך שפריסה שתיפתח
     * בלעדיו הייתה גובה תשלום אחד ונתקעת — מול לקוח שכבר סוכם איתו אחרת.
     */
    public function test_a_plan_is_refused_without_a_saved_card(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();

        Livewire::test(ManualCharge::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 7000,
                'description' => 'פריסת חוב',
                'installments' => 14,
            ])
            ->call('submit');

        $this->assertSame(0, Subscription::where('customer_id', $customer->id)->count());
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    /** הטופס נטען, ושני הכיוונים מוצעים בו. */
    public function test_the_form_offers_both_directions(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSubscription::class)
            ->assertOk()
            ->assertSee('מספר תשלומים (פריסת חוב)')
            ->assertSee('חישוב מסכום כולל');
    }

    /*
    | ----------------------------------------------------------------
    | תיאור החיוב
    | ----------------------------------------------------------------
    */

    /**
     * חיוב בפריסה מתואר כתשלום מספר X, ולא בתאריכים.
     *
     * תשלום בפריסה אינו עבור חודש כלשהו — הוא חלק מסכום שסוכם. "01/09 עד
     * 01/10" על חשבונית כזו קורא ללקוח כאילו נגבה ממנו שירות חודשי שלא הזמין.
     */
    public function test_an_instalment_is_described_by_its_number(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true);

        $plan = $this->plan(14);

        $this->assertSame(
            'פריסת חוב — תשלום 1 מתוך 14',
            $plan->chargeDescription(now(), now()->addMonth()),
        );

        ChargeSubscriptionJob::dispatchSync($plan->id);

        // אותה תקופה, אחרי שנגבתה — עדיין "תשלום 1". החשבונית מונפקת אחרי
        // שהחיוב סומן כמוצלח, וספירה של "כמה שולמו ועוד אחד" הייתה שולחת
        // ללקוח חשבונית ראשונה שכתוב עליה "תשלום 2 מתוך 14".
        $charge = $plan->charges()->latest('id')->sole();

        $this->assertSame(
            'פריסת חוב — תשלום 1 מתוך 14',
            $plan->refresh()->chargeDescription($charge->period_start, $charge->period_end),
        );

        // והתקופה הבאה היא 2.
        $this->assertSame(
            'פריסת חוב — תשלום 2 מתוך 14',
            $plan->chargeDescription($charge->period_end, $charge->period_end->copy()->addMonth()),
        );
    }

    /** מנוי רגיל ממשיך להיות מתואר בתקופה שנגבתה — שם זה בדיוק מה שנקנה. */
    public function test_an_ordinary_subscription_is_still_described_by_its_period(): void
    {
        $subscription = Subscription::factory()->create([
            'plan_id' => null, 'name' => 'אחסון', 'price_agorot_override' => 10000,
            'installments_total' => null,
        ]);

        $this->assertStringContainsString(
            'עד',
            $subscription->chargeDescription(now(), now()->addMonth()),
        );
    }

    /** ומספר התשלום אינו עולה בגלל ניסיון חוזר שנכשל. */
    public function test_a_failed_attempt_does_not_advance_the_number(): void
    {
        Queue::fake([IssueInvoiceJob::class, SendDunningNotificationJob::class]);
        $this->fakeCardcom(success: false);

        $plan = $this->plan(14);
        ChargeSubscriptionJob::dispatchSync($plan->id);

        $this->assertSame(
            'פריסת חוב — תשלום 1 מתוך 14',
            $plan->refresh()->chargeDescription(now(), now()->addMonth()),
        );
    }

    /** הטקסט שמופיע במסכים. */
    public function test_the_summary_reads_like_a_sentence(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true);

        $plan = $this->plan(14);
        ChargeSubscriptionJob::dispatchSync($plan->id);

        $this->assertSame(
            'שולמו 1 מתוך 14 · נותרו ₪6,500.00',
            $plan->refresh()->installmentSummary(),
        );
    }
}
