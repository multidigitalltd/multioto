<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\StatsOverview;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PluginOrder;
use App\Models\PluginPlan;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "נגבה החודש" — וכמה ממנו יחזור גם בחודש הבא.
 *
 * הסכום לבדו אינו אומר אם חודש טוב היה המנויים עושים את שלהם או חיוב חד-פעמי
 * גדול אחד שלא יחזור. זה ההבדל בין עסק שגדל לעסק שהיה לו שבוע טוב, ועד עכשיו
 * אי אפשר היה לראות אותו מהמסך הראשי.
 */
class StatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function charge(int $totalAgorot, ?int $subscriptionId, array $overrides = []): Charge
    {
        return Charge::create(array_merge([
            'subscription_id' => $subscriptionId,
            'customer_id' => $subscriptionId === null ? Customer::factory()->create()->id : null,
            'amount_agorot' => $totalAgorot,
            'vat_agorot' => 0,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'description' => 'חיוב',
            'charged_at' => now(),
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ], $overrides));
    }

    public function test_collected_this_month_is_split_into_subscriptions_and_one_offs(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        // Two different subscriptions: charges are unique per
        // (subscription, period, attempt), which is the idempotency guard.
        $this->charge(30000, Subscription::factory()->create()->id);   // ₪300 recurring
        $this->charge(20000, Subscription::factory()->create()->id);   // ₪200 recurring
        $this->charge(15000, null);                                    // ₪150 one-off

        Livewire::test(StatsOverview::class)
            ->assertSee('נגבה החודש')
            ->assertSee('₪650.00')                       // the total stays the total
            ->assertSee('מנויים ₪500.00 · חד-פעמי ₪150.00');
    }

    /**
     * רק מה שבאמת נגבה, ורק החודש.
     *
     * חיוב שנכשל אינו כסף שנכנס, וחיוב מהחודש שעבר אינו שייך לשורה הזאת —
     * פיצול שסופר אותם היה מדווח חודש טוב יותר משהיה.
     */
    public function test_only_this_months_successful_charges_are_counted(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->charge(10000, Subscription::factory()->create()->id);
        $this->charge(50000, Subscription::factory()->create()->id, [
            'status' => ChargeStatus::Failed,
            'charged_at' => null,
        ]);
        $this->charge(70000, null, ['charged_at' => now()->subMonthNoOverflow()->startOfMonth()]);
        $this->charge(2500, null);

        Livewire::test(StatsOverview::class)
            ->assertSee('מנויים ₪100.00 · חד-פעמי ₪25.00');
    }

    /**
     * הפירוט החליף את המילים הכלליות שהיו שם.
     *
     * נבדק כך ולא לפי "מנויים ₪0 · חד-פעמי ₪0": לאריח "הכנסה חודשית צפויה" יש
     * בדיוק אותו מבנה תיאור, ובחודש ריק שני האריחים מציגים אפס — כלומר בדיקה
     * לפי הטקסט הזה הייתה עוברת גם בלי השינוי, על סמך האריח השני.
     */
    public function test_the_old_general_wording_is_gone(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(StatsOverview::class)
            ->assertSee('נגבה החודש')
            ->assertDontSee('חיובים שהצליחו החודש');
    }

    /*
    |--------------------------------------------------------------------------
    | מה נחשב "חוזר" — ולמה subscription_id אינו התשובה
    |--------------------------------------------------------------------------
    */

    /**
     * פריסה לתשלומים היא מכירה חד-פעמית, גם אם היא שמורה כמנוי.
     *
     * "חיוב ידני" פורס מכירה אחת לתשלומים ויוצר לשם כך Subscription עם
     * installments_total. ספירת התשלומים האלה כ"מנויים" מדווחת על עסק שימשיך
     * להרוויח ממכירה שכבר הסתיימה — ודווקא התשלום האחרון, שבוודאות לא יחזור,
     * נספר כהכנסה חוזרת.
     */
    public function test_an_installment_plan_is_counted_as_one_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $plan = Subscription::factory()->create(['installments_total' => 12]);
        $this->charge(40000, $plan->id);

        Livewire::test(StatsOverview::class)
            ->assertSee('מנויים ₪0.00 · חד-פעמי ₪400.00');
    }

    /**
     * התשלום הראשון של מסלול מתחדש בחנות הוא הכנסה חוזרת.
     *
     * PluginCheckout גובה את התקופה הראשונה בעמוד מתארח לפני שקיים מנוי, ולכן
     * לחיוב אין subscription_id כלל. המנוי נוצר אחר כך ומקושר לרישיון ולא חזרה
     * לחיוב — כך שמכירה מתחדשת לגמרי נקראה חד-פעמית.
     */
    public function test_a_renewing_storefront_purchase_is_counted_as_recurring(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $charge = $this->charge(60000, null);

        $product = PluginProduct::create(['name' => 'תוסף', 'slug' => 'add-on', 'is_active' => true]);
        $renewing = PluginPlan::create([
            'plugin_product_id' => $product->id,
            'name' => 'שנתי',
            'price_agorot' => 60000,
            'billing_interval' => 'yearly',
            'is_active' => true,
        ]);

        PluginOrder::create([
            'plugin_product_id' => $product->id,
            'plugin_plan_id' => $renewing->id,
            'charge_id' => $charge->id,
            'buyer_name' => 'קונה',
            'buyer_email' => 'buyer@example.co.il',
            'total_agorot' => 60000,
            'status' => PluginOrder::PAID,
            'reference' => 'ORD-1',
        ]);

        Livewire::test(StatsOverview::class)
            ->assertSee('מנויים ₪600.00 · חד-פעמי ₪0.00');
    }

    /** ...ומסלול חנות שאינו מתחדש נשאר חד-פעמי. */
    public function test_a_one_time_storefront_purchase_stays_one_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $charge = $this->charge(60000, null);

        $product = PluginProduct::create(['name' => 'תוסף', 'slug' => 'add-on', 'is_active' => true]);
        $oneTime = PluginPlan::create([
            'plugin_product_id' => $product->id,
            'name' => 'רכישה חד-פעמית',
            'price_agorot' => 60000,
            'billing_interval' => null,
            'is_active' => true,
        ]);

        PluginOrder::create([
            'plugin_product_id' => $product->id,
            'plugin_plan_id' => $oneTime->id,
            'charge_id' => $charge->id,
            'buyer_name' => 'קונה',
            'buyer_email' => 'buyer@example.co.il',
            'total_agorot' => 60000,
            'status' => PluginOrder::PAID,
            'reference' => 'ORD-2',
        ]);

        Livewire::test(StatsOverview::class)
            ->assertSee('מנויים ₪0.00 · חד-פעמי ₪600.00');
    }

    /**
     * הפירוט לא סותר את הסכום שהוא מפרט.
     *
     * שני חצאים שמעוגלים כל אחד לחוד אינם מסתכמים בסכום מעוגל: 50 אגורות בכל
     * צד היו מציגות כותרת ₪1 מעל "₪1 · ₪1". חיובים ידניים מוזנים עד רמת
     * האגורה, אז זה לא מקרה תיאורטי.
     */
    public function test_the_breakdown_never_contradicts_its_own_total(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->charge(50, Subscription::factory()->create()->id);
        $this->charge(50, null);

        Livewire::test(StatsOverview::class)
            ->assertSee('₪1.00')
            ->assertSee('מנויים ₪0.50 · חד-פעמי ₪0.50');
    }
}
