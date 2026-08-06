<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Pages\Collections;
use App\Jobs\CheckMoneyIntegrityJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * לקוח שאמור לשלם בכרטיס — ואין כרטיס.
 *
 * המקרה הזה נפל בין כל הרשתות: המתזמן מדלג עליו (חיוב אוטומטי דורש כרטיס), ולכן
 * לא מתבצע ניסיון, ולכן המנוי לעולם לא הופך ל"בפיגור" ולא מגיע למסך החייבים —
 * ורשימת הגבייה הידנית מתעלמת ממנו כי אמצעי התשלום שלו אינו ידני. התוצאה: לקוח
 * חייב כסף בשקט, וכל מסך במערכת מראה שהכול תקין.
 */
class AwaitingCardTest extends TestCase
{
    use RefreshDatabase;

    /** לקוח שהוגדר לתשלום בכרטיס, בלי כרטיס שמור, שמועד החיוב שלו עבר. */
    private function awaitingCard(array $overrides = [], array $customerOverrides = []): Subscription
    {
        $customer = Customer::factory()->create(array_merge(['payment_method' => 'credit_card'], $customerOverrides));
        $plan = Plan::factory()->create(['price_agorot' => 10000]);

        return Subscription::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDays(3),
        ], $overrides));
    }

    /** הוא נתפס עכשיו — בדיוק המקרה שאיש לא ראה. */
    public function test_the_scope_catches_a_card_customer_with_no_card(): void
    {
        $stuck = $this->awaitingCard();

        $this->assertSame([$stuck->id], Subscription::query()->awaitingCardOverdue()->pluck('id')->all());
    }

    /** בלי אמצעי תשלום מוגדר — ברירת המחדל היא כרטיס, ולכן הוא נספר גם הוא. */
    public function test_a_customer_with_no_payment_method_counts_as_card(): void
    {
        $stuck = $this->awaitingCard(customerOverrides: ['payment_method' => null]);

        $this->assertSame([$stuck->id], Subscription::query()->awaitingCardOverdue()->pluck('id')->all());
    }

    /** מי שמשלם בהעברה בנקאית שייך לרשימת הגבייה הידנית, לא לכאן. */
    public function test_a_bank_transfer_customer_is_not_awaiting_a_card(): void
    {
        $this->awaitingCard(customerOverrides: ['payment_method' => 'bank_transfer']);

        $this->assertSame([], Subscription::query()->awaitingCardOverdue()->pluck('id')->all());
    }

    /** מנוי שמועדו טרם הגיע אינו חוב — הוא פשוט עוד לא נגבה. */
    public function test_a_future_charge_date_is_not_overdue(): void
    {
        $this->awaitingCard(['next_charge_at' => now()->addWeek()]);

        $this->assertSame([], Subscription::query()->awaitingCardOverdue()->pluck('id')->all());
    }

    /** מנוי מבוטל אינו חייב דבר. */
    public function test_a_canceled_subscription_is_ignored(): void
    {
        $this->awaitingCard(['status' => SubscriptionStatus::Canceled]);

        $this->assertSame([], Subscription::query()->awaitingCardOverdue()->pluck('id')->all());
    }

    /** מסך הגבייה מציג אותו, ואומר במפורש שהוא לא ייגבה לבד. */
    public function test_the_collections_screen_shows_him(): void
    {
        $this->actingAs(User::factory()->create());
        $stuck = $this->awaitingCard();

        Livewire::test(Collections::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$stuck])
            ->assertSee('ממתין לכרטיס')
            ->assertSee('אין כרטיס שמור');
    }

    /**
     * "פעיל" ליד לקוח שחייב כסף זה בדיוק מה שהסתיר את המקרה — הסטטוס הגולמי
     * אינו מוצג כאן.
     */
    public function test_the_screen_does_not_call_him_active(): void
    {
        $this->actingAs(User::factory()->create());
        $this->awaitingCard();

        Livewire::test(Collections::class)->assertDontSee('פעיל');
    }

    /** והמונה בתפריט סופר גם אותו. */
    public function test_the_navigation_badge_counts_him(): void
    {
        $this->awaitingCard();

        $this->assertSame('1', Collections::getNavigationBadge());
    }

    /** הבדיקה הכספית היומית מדווחת עליו — מי שלא נכנס למסך יקבל מייל. */
    public function test_the_daily_money_check_reports_him(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);
        $this->awaitingCard();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'ואין כרטיס שמור'));
    }

    /** ובלי מקרים כאלה — הבדיקה שותקת, כמו תמיד. */
    public function test_the_daily_check_stays_silent_when_there_is_nothing_to_say(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertNothingSent();
    }
}
