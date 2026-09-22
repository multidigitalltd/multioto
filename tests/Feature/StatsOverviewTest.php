<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\StatsOverview;
use App\Models\Charge;
use App\Models\Customer;
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
            ->assertSee('₪ 650')                       // the total stays the total
            ->assertSee('מנויים ₪500 · חד-פעמי ₪150');
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
            ->assertSee('מנויים ₪100 · חד-פעמי ₪25');
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
}
