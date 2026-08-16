<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\RemindExpiringLicensesJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\License;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * חידוש רישיון = חיוב שהצליח.
 *
 * הרישיונות נבנו על מכונת החיובים הקיימת ולא לצידה: רישיון שנמכר במנוי מחויב,
 * מחושבן, נגבה שוב בכישלון ונרדף בדאנינג — באותו קוד בדיוק. מסלול גבייה שני,
 * ייעודי לרישיונות, היה מקום שני שבו כסף יכול להשתבש, והראשון שכולם היו שוכחים
 * להסתכל בו.
 */
class LicenseRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['licensing.secret' => 'test-license-secret']);
    }

    /** @return array{0: License, 1: Subscription} */
    private function licenseOnSubscription(?string $expiresAt = null): array
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        $product = PluginProduct::create(['slug' => 'enhancer', 'name' => 'משפר חנויות']);

        [$license] = License::issue([
            'plugin_product_id' => $product->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'sites_limit' => 1,
            'expires_at' => $expiresAt,
        ]);

        return [$license, $subscription];
    }

    private function charge(Subscription $subscription, string $periodEnd, ChargeStatus $status, int $attempt = 1): Charge
    {
        return Charge::create([
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => $status,
            'attempt_number' => $attempt,
            'period_start' => now()->toDateString(),
            'period_end' => $periodEnd,
            'description' => 'מנוי',
        ]);
    }

    /** חיוב שהצליח דוחה את התוקף לסוף התקופה ששולמה. */
    public function test_a_successful_charge_extends_the_licence_to_the_paid_period(): void
    {
        [$license, $subscription] = $this->licenseOnSubscription(now()->addDays(3)->toDateString());

        $this->charge($subscription, now()->addYear()->toDateString(), ChargeStatus::Succeeded);

        $this->assertSame(now()->addYear()->toDateString(), $license->fresh()->expires_at->toDateString());
    }

    /** חיוב שנכשל אינו מזיז דבר — הרישיון פג בזמנו והדאנינג רודף אחרי הכסף. */
    public function test_a_failed_charge_changes_nothing(): void
    {
        $expiry = now()->addDays(3)->toDateString();
        [$license, $subscription] = $this->licenseOnSubscription($expiry);

        $this->charge($subscription, now()->addYear()->toDateString(), ChargeStatus::Failed);

        $this->assertSame($expiry, $license->fresh()->expires_at->toDateString());
    }

    /**
     * התוקף לעולם אינו נסוג.
     *
     * חיובים נרשמים מחדש, מתגלים בהתאמה מאוחרת ומגיעים לא לפי הסדר. חיוב ישן
     * שנשמר שוב אחרי חידוש היה מקצר רישיון ששולם עליו — והלקוח היה מגלה זאת
     * כשהעדכונים נעצרים.
     */
    public function test_an_older_charge_never_shortens_a_licence(): void
    {
        [$license, $subscription] = $this->licenseOnSubscription();

        $this->charge($subscription, now()->addYear()->toDateString(), ChargeStatus::Succeeded);
        // אותה תקופה, ניסיון אחר — כך נראה חיוב ישן שנרשם שוב.
        $this->charge($subscription, now()->addMonth()->toDateString(), ChargeStatus::Succeeded, attempt: 2);

        $this->assertSame(now()->addYear()->toDateString(), $license->fresh()->expires_at->toDateString());
    }

    /** רישיון חודשי נדחה בחודש, כמו התקופה ששולמה. */
    public function test_a_monthly_licence_moves_by_a_month(): void
    {
        [$license, $subscription] = $this->licenseOnSubscription(now()->toDateString());

        $this->charge($subscription, now()->addMonth()->toDateString(), ChargeStatus::Succeeded);

        $this->assertSame(now()->addMonth()->toDateString(), $license->fresh()->expires_at->toDateString());
    }

    /** רישיון שאינו קשור למנוי אינו זז מחיוב של מישהו אחר. */
    public function test_a_licence_without_a_subscription_is_untouched(): void
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        $product = PluginProduct::create(['slug' => 'enhancer', 'name' => 'משפר חנויות']);

        [$license] = License::issue([
            'plugin_product_id' => $product->id,
            'sites_limit' => 1,
            'expires_at' => now()->addDay()->toDateString(),
        ]);

        $this->charge($subscription, now()->addYear()->toDateString(), ChargeStatus::Succeeded);

        $this->assertSame(now()->addDay()->toDateString(), $license->fresh()->expires_at->toDateString());
    }

    /*
    | ----------------------------------------------------------------
    | לפני שזה פג
    | ----------------------------------------------------------------
    */

    /** רישיון שאינו מתחדש לבד — הצוות מקבל תזכורת לפני הפקיעה. */
    public function test_the_team_is_warned_before_a_manual_licence_lapses(): void
    {
        $product = PluginProduct::create(['slug' => 'enhancer', 'name' => 'משפר חנויות']);
        $customer = Customer::factory()->create(['name' => 'חנות הבית']);

        License::issue([
            'plugin_product_id' => $product->id,
            'customer_id' => $customer->id,
            'sites_limit' => 1,
            'expires_at' => now()->addDays(7)->toDateString(),
        ]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->withArgs(function (string $title, string $body): bool {
            return str_contains($title, 'לקראת פקיעה') && str_contains($body, 'חנות הבית');
        });

        (new RemindExpiringLicensesJob)->handle($team);
    }

    /**
     * ורישיון שמתחדש במנוי — לא.
     *
     * התוקף שלו זז כשהחיוב מצליח, ואם החיוב נכשל הדאנינג כבר רודף אחריו. שתי
     * התראות על אותה בעיה מאמנות אנשים לא לקרוא אף אחת מהן.
     */
    public function test_a_licence_that_renews_itself_is_not_warned_about(): void
    {
        $this->licenseOnSubscription(now()->addDays(7)->toDateString());

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new RemindExpiringLicensesJob)->handle($team);
    }
}
