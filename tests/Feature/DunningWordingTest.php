<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\License;
use App\Models\PluginProduct;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\Billing\DunningMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * מה מכתב הגבייה מאיים לכבות.
 *
 * סולם הדאנינג נכתב כשלכל מנוי היה אתר מתארח, ולכן שני השלבים האחרונים שלו
 * מדברים על השעיית אתר. מנוי שמחדש רישיון תוסף אינו יכול להשעות שום דבר —
 * התוסף ימשיך לעבוד בכל מקרה — ומכתב שאומר אחרת הוא גם לא נכון וגם הדרך
 * המהירה ביותר ללמד לקוחות שהתכתובת שלנו על כסף אינה מדויקת.
 */
class DunningWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // הבדיקה היא על מה נרשם באירוע, לא על המסירה עצמה — והמסירה הייתה
        // מנסה לפנות ל-WAHA אמיתי.
        Queue::fake();
    }

    private function subscription(?Site $site): Subscription
    {
        $customer = Customer::factory()->create(['email' => 'buyer@example.co.il']);

        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'site_id' => $site?->id,
            'status' => SubscriptionStatus::Active,
            'dunning_stage' => 3,
        ]);
    }

    private function charge(Subscription $subscription): Charge
    {
        return Charge::create([
            'subscription_id' => $subscription->id,
            'amount_agorot' => 20000, 'vat_agorot' => 3600, 'total_agorot' => 23600,
            'status' => ChargeStatus::Failed, 'attempt_number' => 4,
            'period_start' => today()->toDateString(), 'period_end' => today()->addYear()->toDateString(),
        ]);
    }

    /** מנוי עם אתר — הנוסח לא משתנה. יש כאן אתר, והוא באמת יושעה. */
    public function test_a_subscription_with_a_site_keeps_the_original_wording(): void
    {
        $site = Site::factory()->create();
        $subscription = $this->subscription($site);

        app(DunningMachine::class)->handleFailure($subscription, $this->charge($subscription));

        $this->assertSame('site_suspended', $subscription->dunningEvents()->latest('id')->first()->template_key);
    }

    /** מנוי שמחדש רישיון — נוסח שאומר מה באמת ייעצר: העדכונים, לא התוסף. */
    public function test_a_licence_subscription_is_told_that_the_plugin_keeps_working(): void
    {
        $subscription = $this->subscription(null);
        $product = PluginProduct::create(['slug' => 'p', 'name' => 'תוסף']);
        License::issue([
            'plugin_product_id' => $product->id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'sites_limit' => 1,
            'expires_at' => today()->addDays(20)->toDateString(),
        ]);

        app(DunningMachine::class)->handleFailure($subscription, $this->charge($subscription));

        $event = $subscription->dunningEvents()->latest('id')->first();
        $this->assertSame('site_suspended_license', $event->template_key);

        $body = __('dunning.site_suspended_license.body', [
            'name' => 'דנה', 'plan' => 'רישיון', 'amount' => '236.00',
            'update_link' => 'https://example.test', 'until' => '01/01/2027',
        ]);
        $this->assertStringContainsString('התוסף ממשיך לעבוד', $body);
        $this->assertStringNotContainsString('האתר הושעה', $body);
    }

    /** ומנוי בלי אתר ובלי רישיון — המנוי נעצר, ושום אתר לא נופל. */
    public function test_a_siteless_subscription_is_not_threatened_with_a_suspension(): void
    {
        $subscription = $this->subscription(null);

        app(DunningMachine::class)->handleFailure($subscription, $this->charge($subscription));

        $this->assertSame('site_suspended_no_site', $subscription->dunningEvents()->latest('id')->first()->template_key);
        $this->assertStringNotContainsString('האתר', __('dunning.site_suspended_no_site.subject'));
    }

    /**
     * הטקסטים באמת מתורגמים, ולא נשלחים כשם המפתח.
     *
     * lang/ מכיל שפה אחת, והמחרוזות בו נשלחות ללקוחות. כשה-locale אינו he,
     * ‎__()‎ מחזיר את המפתח עצמו — ולקוח שהכרטיס שלו נדחה מקבל הודעה שכל גופה
     * הוא "dunning.payment_failed.body". לכן השפה נעולה בקוד, וזו הבדיקה שהיא
     * תישאר נעולה.
     */
    public function test_customer_facing_texts_are_actually_translated(): void
    {
        $this->assertSame('he', app()->getLocale());

        foreach (array_keys((array) __('dunning')) as $key) {
            $this->assertNotSame("dunning.{$key}.body", __("dunning.{$key}.body"));
            $this->assertNotSame("dunning.{$key}.subject", __("dunning.{$key}.subject"));
        }
    }

    /**
     * שלבים מוקדמים אינם מזכירים אתר, ולכן אין להם גרסה נפרדת — והמנגנון נופל
     * בחזרה לנוסח המקורי במקום להמציא מפתח שאינו קיים.
     */
    public function test_a_stage_without_a_variant_falls_back_to_its_own_wording(): void
    {
        $subscription = $this->subscription(null);
        $subscription->update(['dunning_stage' => 0]);

        app(DunningMachine::class)->handleFailure($subscription, $this->charge($subscription));

        $this->assertSame('payment_failed', $subscription->dunningEvents()->latest('id')->first()->template_key);
    }
}
