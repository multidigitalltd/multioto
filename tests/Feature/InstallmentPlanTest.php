<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendDunningNotificationJob;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
