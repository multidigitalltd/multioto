<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManualCharge;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\ManualChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * חיוב ידני שנכשל, ומה שהמסך אומר על מע״מ.
 *
 * שתי תקלות שנראו קטנות והיו יקרות: כרטיס שנדחה השאיר את הכסף בלתי ניתן לגבייה
 * אלא בהקלדה מחדש מהזיכרון, ושדה סכום שלא אמר לפני או אחרי מע״מ הפך כל שיחת
 * מחיר לחשבון בראש.
 */
class ManualChargeRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.vat_rate' => 0.18]);
    }

    private function failedCharge(Customer $customer, array $overrides = []): Charge
    {
        return Charge::create($overrides + [
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Failed,
            'attempt_number' => 1,
            'description' => 'שירות חד-פעמי',
            'failure_reason' => 'הכרטיס נדחה',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
    }

    private function withSavedCard(Customer $customer): void
    {
        $customer->paymentTokens()->create([
            'cardcom_token' => 'tok-1',
            'card_last4' => '4242',
            'status' => TokenStatus::Active,
        ]);
    }

    /*
    | ----------------------------------------------------------------
    | לחייב שוב
    | ----------------------------------------------------------------
    */

    /** עם כרטיס שמור — נוצר חיוב חדש והכרטיס מחויב. */
    public function test_a_failed_charge_can_be_charged_again_on_the_saved_card(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create();
        $this->withSavedCard($customer);
        $failed = $this->failedCharge($customer);

        $retry = app(ManualChargeService::class)->retry($failed);

        $this->assertSame('token', $retry['method']);
        $this->assertSame(11800, $retry['charge']->total_agorot);
        $this->assertSame('שירות חד-פעמי', $retry['charge']->description);
        $this->assertSame(ChargeStatus::Pending, $retry['charge']->status);
        Queue::assertPushed(ProcessManualChargeJob::class);
    }

    /**
     * הניסיון שנכשל נשאר בדיוק כפי שהוא.
     *
     * מה שקארדקום ענתה הוא עובדה, והכלל שכל תשובה נרשמת שווה יותר מרשימה
     * מסודרת. הניסיון החדש נושא את המספר הבא, כך שההיסטוריה נקראת כמו שהיה.
     */
    public function test_the_failed_attempt_is_kept_and_the_new_one_is_numbered_after_it(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create();
        $this->withSavedCard($customer);
        $failed = $this->failedCharge($customer);

        $retry = app(ManualChargeService::class)->retry($failed);

        $this->assertSame(ChargeStatus::Failed, $failed->fresh()->status);
        $this->assertSame('הכרטיס נדחה', $failed->fresh()->failure_reason);
        $this->assertSame(2, $retry['charge']->attempt_number);
        $this->assertSame(2, Charge::count());
    }

    /** בלי כרטיס שמור — נוצר עמוד תשלום חדש וקישור לשליחה. */
    public function test_without_a_saved_card_a_fresh_payment_page_is_created(): void
    {
        Http::fake(['*' => Http::response([
            'ResponseCode' => 0,
            'Url' => 'https://secure.cardcom.solutions/pay/xyz',
            'LowProfileId' => 'lp-9',
        ])]);

        $customer = Customer::factory()->create();
        $failed = $this->failedCharge($customer);

        $retry = app(ManualChargeService::class)->retry($failed);

        $this->assertSame('link', $retry['method']);
        $this->assertNotNull($retry['pay_url']);
        $this->assertSame(11800, $retry['charge']->total_agorot);
    }

    /**
     * חיוב של מנוי אינו נגבה שוב מכאן.
     *
     * מנגנון הגבייה כבר מנסה אותו לפי לוח הזמנים שלו ומודיע ללקוח. חיוב שני
     * מכאן היה נגבה פעמיים ביום שבו גם המנגנון פועל.
     */
    public function test_a_subscription_charge_is_left_to_the_dunning_machine(): void
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        $failed = $this->failedCharge($customer, ['subscription_id' => $subscription->id]);

        $this->expectExceptionMessage('מנגנון הגבייה');
        app(ManualChargeService::class)->retry($failed);
    }

    /** וחיוב שלא נכשל אינו נגבה שוב בכלל. */
    public function test_only_a_failed_charge_can_be_retried(): void
    {
        $customer = Customer::factory()->create();
        $charge = $this->failedCharge($customer, ['status' => ChargeStatus::Succeeded]);

        $this->expectExceptionMessage('רק חיוב שנכשל');
        app(ManualChargeService::class)->retry($charge);
    }

    /** חיוב שהיה פטור ממע״מ נגבה שוב כפטור — לא כמו שהיה נגבה היום. */
    public function test_a_vat_exempt_charge_is_retried_vat_exempt(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $this->withSavedCard($customer);
        $failed = $this->failedCharge($customer, [
            'amount_agorot' => 11800, 'vat_agorot' => 0, 'total_agorot' => 11800,
        ]);

        $retry = app(ManualChargeService::class)->retry($failed);

        $this->assertSame(0, $retry['charge']->vat_agorot);
        $this->assertSame(11800, $retry['charge']->amount_agorot);
    }

    /*
    | ----------------------------------------------------------------
    | מע״מ במסך החיוב
    | ----------------------------------------------------------------
    */

    /** סכום "לפני מע״מ" נגבה עם המע״מ. */
    public function test_an_amount_entered_before_vat_is_charged_with_it(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create();
        $this->withSavedCard($customer);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManualCharge::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 500,
                'amount_excludes_vat' => true,
                'description' => 'שירות',
            ])
            ->call('submit');

        // 500 ₪ פלוס 18% — הסכום שיירד מהכרטיס בפועל.
        $this->assertSame(59000, Charge::sole()->total_agorot);
        $this->assertSame(50000, Charge::sole()->amount_agorot);
    }

    /** ואותו סכום "כולל מע״מ" נגבה כמו שהוא. */
    public function test_the_same_amount_entered_including_vat_is_charged_as_typed(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create();
        $this->withSavedCard($customer);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManualCharge::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 500,
                'amount_excludes_vat' => false,
                'description' => 'שירות',
            ])
            ->call('submit');

        $this->assertSame(50000, Charge::sole()->total_agorot);
    }

    /** ופטור ממע״מ מנצח את המתג — אין מה להוסיף. */
    public function test_a_vat_exempt_charge_adds_nothing_even_when_the_toggle_is_on(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create();
        $this->withSavedCard($customer);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManualCharge::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 500,
                'amount_excludes_vat' => true,
                'vat_exempt' => true,
                'description' => 'שירות',
            ])
            ->call('submit');

        $this->assertSame(50000, Charge::sole()->total_agorot);
        $this->assertSame(0, Charge::sole()->vat_agorot);
    }

    /** המסך מציג את שלושת המספרים, כדי שאיש לא ינחש איזה מהם השדה התכוון אליו. */
    public function test_the_screen_shows_net_vat_and_what_will_actually_be_taken(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManualCharge::class)
            ->fillForm(['amount' => 500, 'amount_excludes_vat' => true])
            ->assertSee('לפני מע״מ')
            ->assertSee('לתשלום בפועל');
    }
}
