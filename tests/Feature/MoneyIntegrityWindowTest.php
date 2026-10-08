<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\CheckMoneyIntegrityJob;
use App\Jobs\IssueInvoiceJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Linet\InvoiceIssuer;
use App\Services\Linet\LinetClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * חיוב שהצליח בלי חשבונית — עד שהוא מטופל.
 *
 * כסף שנלקח בלי מסמך הוא חשיפת מס שאינה מתיישנת, וממצא שנושר מהדוח בגלל גיל
 * הוא ממצא שנשכח. במערכת אמיתית התגלה מקרה כזה חודש אחרי שעבודת החשבונית
 * נכשלה — הדוח כבר מזמן הפסיק להזכיר אותו.
 */
class MoneyIntegrityWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'billing.notifications.team_email' => 'team@example.com',
            'backup.enabled' => false,
        ]);
    }

    /** חיוב שהצליח, בלי חשבונית, בגיל נתון. */
    private function chargeWithoutInvoice(int $daysAgo): Charge
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create()->id,
        ]);

        $charge = $subscription->charges()->create([
            'customer_id' => $customer->id,
            'status' => ChargeStatus::Succeeded,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'attempt_number' => 1,
            'period_start' => now()->subDays($daysAgo)->toDateString(),
            'period_end' => now()->subDays($daysAgo)->addMonth()->toDateString(),
            'charged_at' => now()->subDays($daysAgo),
        ]);

        $charge->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $charge;
    }

    private function report(): void
    {
        (new CheckMoneyIntegrityJob)->handle();
    }

    /** חיוב מאתמול בלי חשבונית — מדווח. */
    public function test_a_recent_charge_without_an_invoice_is_reported(): void
    {
        $this->chargeWithoutInvoice(1);

        $this->report();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'ללא חשבונית'));
    }

    /**
     * וגם חיוב מלפני חודשיים. זה הלב: חשיפת מס אינה מתיישנת אחרי שבועיים, וזה
     * בדיוק המקרה שנעלם מהדוח במערכת האמיתית.
     */
    public function test_an_old_charge_without_an_invoice_is_still_reported(): void
    {
        $this->chargeWithoutInvoice(60);

        $this->report();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'ללא חשבונית'));
    }

    /**
     * חיוב מהרגע האחרון עדיין בתוך חלון החסד — עבודת החשבונית רצה ברקע ועוד
     * לא סיימה, ולדווח עליה עכשיו זה להתריע על משהו שקורה כרגע.
     */
    public function test_a_charge_within_the_grace_period_is_not_reported_yet(): void
    {
        $this->chargeWithoutInvoice(0);

        $this->report();

        Mail::assertNothingSent();
    }

    public function test_zero_settlement_is_not_a_missing_tax_invoice(): void
    {
        $charge = $this->chargeWithoutInvoice(1);
        $charge->update(['amount_agorot' => 0, 'vat_agorot' => 0, 'total_agorot' => 0]);

        $this->report();

        Mail::assertNothingSent();
    }

    public function test_zero_settlement_cannot_issue_an_invoice_through_the_queue_or_manual_button(): void
    {
        $charge = $this->chargeWithoutInvoice(1);
        $charge->update(['amount_agorot' => 0, 'vat_agorot' => 0, 'total_agorot' => 0]);
        $linet = $this->mock(LinetClient::class);
        $linet->shouldNotReceive('issueDocument');
        $issuer = new InvoiceIssuer($linet);

        (new IssueInvoiceJob($charge->id))->handle($issuer);
        $this->assertSame(['ok' => true, 'error' => null], $issuer->issue($charge));
        $this->assertFalse($charge->invoice()->exists());
    }
}
