<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\SubscriptionStatus;
use App\Jobs\RequestMissingCardJob;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * בקשת כרטיס מלקוח שמועד החיוב שלו עבר בלי כרטיס שמור.
 *
 * עד עכשיו לא קרה כלום: בלי כרטיס אין ניסיון חיוב, בלי ניסיון אין כישלון, ובלי
 * כישלון מכונת הדאנינג — שרודפת אחרי כישלונות — לא מתחילה. הלקוח לא ידע, והשירות
 * המשיך לפעול בלי תשלום.
 */
class RequestMissingCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        config([
            'billing.cards.missing_request.interval_days' => 3,
            'billing.cards.missing_request.max_requests' => 5,
        ]);
    }

    private function awaitingCard(array $overrides = [], array $customerOverrides = []): Subscription
    {
        $customer = Customer::factory()->create(array_merge([
            'payment_method' => 'credit_card',
            'email' => 'lakoach@example.com',
            'phone' => null,
            'whatsapp_jid' => null,
        ], $customerOverrides));

        return Subscription::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['price_agorot' => 10000])->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDays(2),
        ], $overrides));
    }

    private function ask(): void
    {
        app()->call([app(RequestMissingCardJob::class), 'handle']);
    }

    private function cardRequests(Customer $customer): int
    {
        return NotificationLog::query()
            ->where('customer_id', $customer->id)
            ->where('type', NotificationType::CardLink)
            ->count();
    }

    /** הלקוח מקבל בקשה להזין כרטיס — הדבר שקודם לא קרה בכלל. */
    public function test_it_asks_the_customer_for_a_card(): void
    {
        $subscription = $this->awaitingCard();

        $this->ask();

        $this->assertSame(1, $this->cardRequests($subscription->customer));
    }

    /** ההודעה אומרת את האמת: הגיע מועד ואין כרטיס — לא "לא הצלחנו לחייב". */
    public function test_the_message_says_what_actually_happened(): void
    {
        $this->awaitingCard();

        $this->ask();

        $body = (string) NotificationLog::query()->latest('id')->value('body');
        $this->assertStringContainsString('לא שמורים אצלנו פרטי כרטיס', $body);
        $this->assertStringNotContainsString('לא הצלחנו לחייב', $body);
    }

    /** לא מנדנדים: בקשה נוספת רק אחרי חלוף המרווח. */
    public function test_it_does_not_ask_again_within_the_interval(): void
    {
        $subscription = $this->awaitingCard();

        $this->ask();
        $this->ask();

        $this->assertSame(1, $this->cardRequests($subscription->customer));
    }

    /** ואחרי שחלף — שואלים שוב. */
    public function test_it_asks_again_once_the_interval_has_passed(): void
    {
        $subscription = $this->awaitingCard();

        $this->ask();
        $this->travel(4)->days();
        $this->ask();

        $this->assertSame(2, $this->cardRequests($subscription->customer));
    }

    /** יש תקרה: מי שהתעלם חמש פעמים לא ישתכנע בשישית. */
    public function test_it_stops_after_the_maximum(): void
    {
        config(['billing.cards.missing_request.max_requests' => 2]);
        $subscription = $this->awaitingCard();

        $this->ask();
        $this->travel(4)->days();
        $this->ask();
        $this->travel(4)->days();
        $this->ask();

        $this->assertSame(2, $this->cardRequests($subscription->customer));
    }

    /**
     * שלושה מנויים של אותו לקוח — הודעה אחת. הקישור שייך ללקוח, ושלוש הודעות
     * זהות בדקה אחת נקראות כמכונה שאיבדה את החשבון עם מי היא מדברת.
     */
    public function test_one_customer_gets_one_message_however_many_subscriptions(): void
    {
        $subscription = $this->awaitingCard();
        foreach (range(1, 2) as $ignored) {
            Subscription::factory()->create([
                'customer_id' => $subscription->customer_id,
                'plan_id' => Plan::factory()->create()->id,
                'token_id' => null,
                'status' => SubscriptionStatus::Active,
                'next_charge_at' => now()->subDay(),
            ]);
        }

        $this->ask();

        $this->assertSame(1, $this->cardRequests($subscription->customer));
    }

    /** מי שביקש להפסיק דיוור עדיין מקבל — זו הודעת שירות, לא פרסום. */
    public function test_a_marketing_opt_out_does_not_silence_a_service_message(): void
    {
        $subscription = $this->awaitingCard(customerOverrides: [
            'marketing_opt_out_at' => now()->subMonth(),
            'marketing_opt_out_channel' => 'email',
        ]);

        $this->ask();

        $this->assertSame(1, $this->cardRequests($subscription->customer));
    }

    /** מנוי שמועדו טרם הגיע — אין על מה לבקש. */
    public function test_a_subscription_not_yet_due_is_left_alone(): void
    {
        $subscription = $this->awaitingCard(['next_charge_at' => now()->addWeek()]);

        $this->ask();

        $this->assertSame(0, $this->cardRequests($subscription->customer));
    }

    /**
     * לקוח בתקופת ניסיון לא מקבל דרישה לכרטיס: הוא אינו חייב דבר, וכל לקוח
     * חדש נקלט בדיוק כך — בלי כרטיס, בכוונה.
     */
    public function test_a_trialing_customer_is_not_asked_as_if_in_debt(): void
    {
        $subscription = $this->awaitingCard(['status' => SubscriptionStatus::Trialing]);

        $this->ask();

        $this->assertSame(0, $this->cardRequests($subscription->customer));
    }

    /** ומי שמשלם בהעברה בנקאית לא מקבל בקשה לכרטיס. */
    public function test_a_bank_transfer_customer_is_not_asked_for_a_card(): void
    {
        $subscription = $this->awaitingCard(customerOverrides: ['payment_method' => 'bank_transfer']);

        $this->ask();

        $this->assertSame(0, $this->cardRequests($subscription->customer));
    }

    /** אפס בהגדרה = כיבוי מלא, והטיפול חוזר לצוות. */
    public function test_setting_the_maximum_to_zero_switches_it_off(): void
    {
        config(['billing.cards.missing_request.max_requests' => 0]);
        $subscription = $this->awaitingCard();

        $this->ask();

        $this->assertSame(0, $this->cardRequests($subscription->customer));
    }
}
