<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\PluginSales;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\License;
use App\Models\PluginOrder;
use App\Models\PluginPlan;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Licensing\LicenseMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * המספרים של מכירת התוספים.
 *
 * הבדיקה המרכזית כאן היא לא חשבונית אלא הגדרה: שיעור חידוש שנמדד לפי תאריכי
 * תפוגה תמיד יוצא 100%, מפני שחידוש דוחף את התאריך קדימה והרישיון עוזב את
 * החלון שבו נספר. לכן הוא נמדד על ניסיונות גבייה — שורות שנכתבות פעם אחת
 * להצלחה ופעם אחת לכישלון, ולא זזות אחר כך.
 */
class LicenseMetricsTest extends TestCase
{
    use RefreshDatabase;

    private PluginProduct $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['licensing.secret' => 'test-license-secret']);

        $this->product = PluginProduct::create(['slug' => 'enhancer', 'name' => 'משפר חנויות']);
        $this->customer = Customer::factory()->create();
    }

    private function metrics(): LicenseMetrics
    {
        return app(LicenseMetrics::class);
    }

    private function license(array $overrides = []): License
    {
        [$license] = License::issue($overrides + [
            'plugin_product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'sites_limit' => 1,
            'includes_updates' => true,
        ]);

        return $license;
    }

    private function subscriptionCharge(Subscription $subscription, ChargeStatus $status, int $attempt = 1): Charge
    {
        return Charge::create([
            'subscription_id' => $subscription->id,
            'amount_agorot' => 20000, 'vat_agorot' => 3600, 'total_agorot' => 23600,
            'status' => $status, 'attempt_number' => $attempt,
            'charged_at' => $status === ChargeStatus::Succeeded ? now() : null,
            'period_start' => today()->toDateString(), 'period_end' => today()->addYear()->toDateString(),
        ]);
    }

    private function licenseSubscription(): Subscription
    {
        $subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'site_id' => null,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->license(['subscription_id' => $subscription->id, 'expires_at' => today()->addYear()->toDateString()]);

        return $subscription;
    }

    /*
    | ----------------------------------------------------------------
    | סקירה
    | ----------------------------------------------------------------
    */

    /** פעיל, פג ומבוטל נספרים בנפרד — ורישיון פג אינו "פעיל". */
    public function test_active_expired_and_revoked_are_counted_apart(): void
    {
        $this->license(['expires_at' => today()->addYear()->toDateString()]);
        $this->license(['expires_at' => today()->subDay()->toDateString()]);
        $this->license(['expires_at' => null, 'includes_updates' => false]);
        $this->license()->update(['status' => License::REVOKED]);

        $overview = $this->metrics()->overview();

        $this->assertSame(4, $overview['total']);
        $this->assertSame(2, $overview['active']);
        $this->assertSame(1, $overview['expired']);
        $this->assertSame(1, $overview['revoked']);
        $this->assertSame(1, $overview['perpetual']);
    }

    /** אתר רשום ואתר שמריץ הם שני מספרים — התקנה שנמחקה משאירה שורה. */
    public function test_a_registered_site_and_a_running_site_are_different_numbers(): void
    {
        $license = $this->license();
        $license->sites()->create(['site_url' => 'live.example.co.il', 'reported_url' => 'https://live.example.co.il',
            'activated_at' => now(), 'last_seen_at' => now()]);
        $license->sites()->create(['site_url' => 'gone.example.co.il', 'reported_url' => 'https://gone.example.co.il',
            'activated_at' => now()->subYear(), 'last_seen_at' => now()->subMonths(6)]);

        $overview = $this->metrics()->overview();

        $this->assertSame(2, $overview['sites']);
        $this->assertSame(1, $overview['sitesLive']);
    }

    /*
    | ----------------------------------------------------------------
    | חידושים
    | ----------------------------------------------------------------
    */

    /**
     * חידוש שנגבה אינו נעלם מהמדידה.
     *
     * זו הנקודה שבגללה המדידה היא על חיובים: אחרי חידוש מוצלח תאריך התפוגה כבר
     * לא בחלון, ומדידה לפיו הייתה מדווחת שלא היה מה לחדש.
     */
    public function test_a_collected_renewal_still_counts_after_the_expiry_moved(): void
    {
        $subscription = $this->licenseSubscription();
        $this->subscriptionCharge($subscription, ChargeStatus::Succeeded);

        $renewals = $this->metrics()->renewals();

        $this->assertSame(1, $renewals['succeeded']);
        $this->assertSame(100.0, (float) $renewals['rate']);
    }

    /** חידוש שנכשל נספר, והסכום שלא נגבה נאמר. */
    public function test_a_failed_renewal_is_counted_with_the_money_it_did_not_collect(): void
    {
        $subscription = $this->licenseSubscription();
        $this->subscriptionCharge($subscription, ChargeStatus::Succeeded, attempt: 1);
        $this->subscriptionCharge($subscription, ChargeStatus::Failed, attempt: 2);

        $renewals = $this->metrics()->renewals();

        $this->assertSame(1, $renewals['failed']);
        $this->assertSame(23600, $renewals['lostAgorot']);
        $this->assertSame(50.0, (float) $renewals['rate']);
    }

    /**
     * ובלי חידושים בכלל — null, לא אפס.
     *
     * "לא היו חידושים לגבות" ו"כל החידושים נכשלו" הם שני מצבים הפוכים, ואסור
     * להם להיראות אותו דבר על המסך.
     */
    public function test_no_renewals_reports_nothing_rather_than_zero_percent(): void
    {
        $this->license(['expires_at' => today()->addYear()->toDateString()]);

        $this->assertNull($this->metrics()->renewals()['rate']);
    }

    /** חיוב שעדיין בתהליך אינו מיטיב עם השיעור ואינו פוגע בו. */
    public function test_an_open_charge_is_kept_out_of_the_rate(): void
    {
        $subscription = $this->licenseSubscription();
        $this->subscriptionCharge($subscription, ChargeStatus::Succeeded, attempt: 1);
        $this->subscriptionCharge($subscription, ChargeStatus::Pending, attempt: 2);

        $renewals = $this->metrics()->renewals();

        $this->assertSame(1, $renewals['open']);
        $this->assertSame(100.0, (float) $renewals['rate']);
    }

    /*
    | ----------------------------------------------------------------
    | הכנסה
    | ----------------------------------------------------------------
    */

    /** הכנסה סופרת גם מכירה עצמאית מהחנות וגם חידוש של מנוי רישיון. */
    public function test_revenue_counts_both_store_orders_and_renewals(): void
    {
        $subscription = $this->licenseSubscription();
        $this->subscriptionCharge($subscription, ChargeStatus::Succeeded);

        $order = Subscription::factory()->create(['customer_id' => $this->customer->id]);
        $storeCharge = $this->subscriptionCharge($order, ChargeStatus::Succeeded);
        PluginOrder::create([
            'plugin_product_id' => $this->product->id,
            'charge_id' => $storeCharge->id,
            'buyer_name' => 'דנה', 'buyer_email' => 'dana@example.co.il',
            'sites_limit' => 1, 'total_agorot' => 23600,
            'status' => PluginOrder::PAID, 'reference' => PluginOrder::newReference(),
        ]);

        $this->assertSame(47200, $this->metrics()->revenue()['agorot']);
    }

    /** וחיוב של מנוי אחסון רגיל אינו נספר כהכנסה מתוספים. */
    public function test_an_ordinary_hosting_charge_is_not_plugin_revenue(): void
    {
        $hosting = Subscription::factory()->create(['customer_id' => $this->customer->id]);
        $this->subscriptionCharge($hosting, ChargeStatus::Succeeded);

        $this->assertSame(0, $this->metrics()->revenue()['agorot']);
    }

    /*
    | ----------------------------------------------------------------
    | רשימות לפעולה
    | ----------------------------------------------------------------
    */

    /** הזמנה ששולמה ולא הונפק בגינה רישיון היא התראה, לא שורה בטבלה. */
    public function test_a_paid_order_without_a_licence_is_surfaced(): void
    {
        $subscription = Subscription::factory()->create(['customer_id' => $this->customer->id]);
        $charge = $this->subscriptionCharge($subscription, ChargeStatus::Succeeded);

        PluginOrder::create([
            'plugin_product_id' => $this->product->id,
            'charge_id' => $charge->id,
            'buyer_name' => 'דנה', 'buyer_email' => 'dana@example.co.il',
            'sites_limit' => 1, 'total_agorot' => 23600,
            'status' => PluginOrder::PAID, 'reference' => PluginOrder::newReference(),
        ]);

        $this->assertCount(1, $this->metrics()->paidButUnfulfilled());
    }

    /** רישיון שפג ולא חודש נכנס לרשימה; רכישה לתמיד אינה "פגה". */
    public function test_lapsed_lists_expiries_and_leaves_perpetual_licences_alone(): void
    {
        $this->license(['expires_at' => today()->subDays(5)->toDateString()]);
        $this->license(['expires_at' => null, 'includes_updates' => false]);

        $lapsed = $this->metrics()->lapsed();

        $this->assertCount(1, $lapsed);
        $this->assertTrue($lapsed->first()->hasExpired());
    }

    /** "פג בקרוב" מציג רק את מה שאין מי שיחדש — השאר מטופל אוטומטית. */
    public function test_expiring_soon_shows_only_what_nothing_renews(): void
    {
        $this->license(['expires_at' => today()->addDays(10)->toDateString()]);

        $subscription = Subscription::factory()->create(['customer_id' => $this->customer->id]);
        $this->license([
            'subscription_id' => $subscription->id,
            'expires_at' => today()->addDays(10)->toDateString(),
        ]);

        $this->assertCount(1, $this->metrics()->expiringSoon());
    }

    /** ולפי תוסף — פעילים והכנסה, כך שאפשר לדעת מה מוכר. */
    public function test_the_per_product_table_reports_what_each_plugin_brought_in(): void
    {
        PluginPlan::create([
            'plugin_product_id' => $this->product->id,
            'name' => 'שנתי', 'price_agorot' => 24000, 'sites_limit' => 1,
            'billing_interval' => 'yearly', 'is_active' => true,
        ]);

        $subscription = $this->licenseSubscription();
        $this->subscriptionCharge($subscription, ChargeStatus::Succeeded);

        $row = $this->metrics()->byProduct()->firstWhere('name', 'משפר חנויות');

        $this->assertSame(1, $row['active']);
        $this->assertSame(23600, $row['agorot']);
        $this->assertSame(1, $this->metrics()->overview()['productsSellable']);
    }

    /*
    | ----------------------------------------------------------------
    | המסך עצמו
    | ----------------------------------------------------------------
    */

    /** המסך נטען ומציג את המספרים. */
    public function test_the_screen_renders_the_figures(): void
    {
        $this->actingAs(User::factory()->create());

        $subscription = $this->licenseSubscription();
        $this->subscriptionCharge($subscription, ChargeStatus::Succeeded);

        $this->get(PluginSales::getUrl())
            ->assertOk()
            ->assertSee('רישיונות פעילים')
            ->assertSee('משפר חנויות');
    }

    /**
     * וכשעוד לא נמכר דבר — המסך אומר זאת.
     *
     * קיר של אפסים נקרא כעסק שנכשל, בזמן שהוא פשוט עוד לא התחיל, ושתי
     * המסקנות מובילות לפעולות הפוכות.
     */
    public function test_the_screen_says_when_nothing_has_been_sold_yet(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(PluginSales::getUrl())
            ->assertOk()
            ->assertSee('עדיין לא הונפק אף רישיון');
    }
}
