<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Mail\LicenseKeyMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\License;
use App\Models\PluginOrder;
use App\Models\PluginPlan;
use App\Models\PluginProduct;
use App\Models\PluginRelease;
use App\Models\Subscription;
use App\Services\Licensing\PluginCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * לקוח קונה לבד ומקבל את התוסף.
 *
 * הצורה של כל זה נקבעת מעובדה אחת: **הקונה עוזב.** הוא עובר לעמוד הסליקה, ומה
 * שחוזר הוא וובהוק, דקות אחר כך, לתהליך שאין לו דפדפן ואין לו session. לכן
 * ההזמנה נכתבת לפני שהוא הולך, וכל מה שקורה אחרי — קורה ממנה.
 *
 * ושום דבר לא מונפק לפני שהכסף הגיע. רישיון שנוצר בתקווה בקופה הוא רישיון
 * שנשאר בידי מי שנטש את עמוד התשלום.
 */
class PluginStoreTest extends TestCase
{
    use RefreshDatabase;

    private PluginProduct $product;

    private PluginPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'licensing.secret' => 'test-license-secret',
            'billing.vat_rate' => 0.18,
        ]);

        $this->product = PluginProduct::create([
            'slug' => 'wc-store-enhancer',
            'name' => 'משפר חנויות',
            'description' => 'משפר את החנות',
            'is_active' => true,
        ]);

        $this->plan = $this->plan(['name' => 'מנוי שנתי', 'price_agorot' => 20000, 'billing_interval' => 'yearly', 'sites_limit' => 2]);
    }

    private function plan(array $attributes = []): PluginPlan
    {
        return PluginPlan::create($attributes + [
            'plugin_product_id' => $this->product->id,
            'name' => 'מסלול',
            'price_agorot' => 20000,
            'sites_limit' => 1,
            'is_active' => true,
        ]);
    }

    /** Cardcom answers with a payment page. */
    private function fakeCardcom(): void
    {
        Http::fake(['*' => Http::response([
            'ResponseCode' => 0,
            'Url' => 'https://secure.cardcom.solutions/pay/abc',
            'LowProfileId' => 'lp-1',
        ])]);
    }

    /*
    | ----------------------------------------------------------------
    | עמוד המכירה
    | ----------------------------------------------------------------
    */

    /** העמוד מציג את המחיר כולל מע״מ ואת מה שנכלל. */
    public function test_the_sales_page_shows_the_price_the_buyer_will_actually_pay(): void
    {
        $this->get(route('store.plugin', ['slug' => 'wc-store-enhancer']))
            ->assertOk()
            ->assertSee('236.00')          // 200 ₪ + מע״מ
            ->assertSee('המחירים כוללים מע״מ')
            ->assertSee('2 אתרים');
    }

    /**
     * וכל מסלול מוצג עם מה שקורה אחרי המכירה.
     *
     * "חד-פעמי בלי עדכונים" אינו מחיר אחר — הוא מוצר אחר, והלקוח צריך לדעת
     * לפני התשלום שלא יקבל גרסאות חדשות.
     */
    public function test_every_plan_shows_what_happens_to_updates(): void
    {
        $this->plan(['name' => 'רכישה לתמיד', 'price_agorot' => 60000, 'updates_months' => null]);
        $this->plan(['name' => 'לתמיד + שנה עדכונים', 'price_agorot' => 80000, 'updates_months' => 12]);

        $this->get(route('store.plugin', ['slug' => 'wc-store-enhancer']))
            ->assertOk()
            ->assertSee('עדכונים כל עוד המנוי פעיל')
            ->assertSee('ללא עדכונים — התוסף שלכם לתמיד, בגרסה הנוכחית')
            ->assertSee('כולל עדכונים ל-12 חודשים');
    }

    /**
     * ואומר את שתי האמיתות שמונעות ויכוח אחרי החיוב.
     *
     * שהרישיון מתחדש מעצמו, ושפקיעה אינה משביתה את התוסף. שתיהן נכונות בין אם
     * כתובות ובין אם לא; ההבדל הוא אם הלקוח גילה אותן מהעמוד או מהחיוב.
     */
    public function test_the_sales_page_says_it_renews_and_that_expiry_does_not_break_the_plugin(): void
    {
        $this->get(route('store.plugin', ['slug' => 'wc-store-enhancer']))
            ->assertSee('מתחדש אוטומטית')
            ->assertSee('התוסף ממשיך לעבוד באתר תמיד');
    }

    /** תוסף שאינו פעיל או בלי מחיר אינו נמכר. */
    public function test_a_plugin_that_is_not_for_sale_is_not_reachable(): void
    {
        $this->product->update(['is_active' => false]);
        $this->get(route('store.plugin', ['slug' => 'wc-store-enhancer']))->assertNotFound();

        // ובלי מסלול פעיל אין מחיר, ולכן אין עמוד מכירה.
        $this->product->update(['is_active' => true]);
        $this->product->plans()->update(['is_active' => false]);
        $this->get(route('store.plugin', ['slug' => 'wc-store-enhancer']))->assertNotFound();
    }

    /*
    | ----------------------------------------------------------------
    | הקופה
    | ----------------------------------------------------------------
    */

    /** רכישה פותחת הזמנה וחיוב, ושולחת לעמוד הסליקה. */
    public function test_buying_records_the_order_and_sends_the_buyer_to_the_payment_page(): void
    {
        $this->fakeCardcom();

        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id,
            'name' => 'יוסי כהן',
            'email' => 'yossi@example.co.il',
            'phone' => '0501234567',
            'terms' => '1',
        ])->assertRedirect('https://secure.cardcom.solutions/pay/abc');

        $order = PluginOrder::sole();
        $this->assertSame(PluginOrder::PENDING, $order->status);
        $this->assertSame(23600, $order->total_agorot);
        $this->assertNotNull($order->charge_id);
        // הלקוח נוצר עכשיו: לוובהוק שיחזור אין טופס לבנות ממנו לקוח.
        $this->assertSame('yossi@example.co.il', $order->customer->email);
    }

    /** ושום רישיון אינו קיים עדיין — לא שולם. */
    public function test_nothing_is_issued_before_the_money_arrives(): void
    {
        $this->fakeCardcom();

        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id, 'name' => 'יוסי', 'email' => 'y@example.co.il', 'terms' => '1',
        ]);

        $this->assertSame(0, License::count());
        $this->assertSame(0, Subscription::count());
    }

    /** לקוח חוזר אינו נוצר פעמיים — עסק אחד, לא שתי יתרות ושני מסלולי גבייה. */
    public function test_a_returning_buyer_is_the_same_customer(): void
    {
        $this->fakeCardcom();
        $existing = Customer::factory()->create(['email' => 'shop@example.co.il']);

        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id, 'name' => 'שם אחר', 'email' => 'SHOP@example.co.il', 'terms' => '1',
        ]);

        $this->assertSame(1, Customer::count());
        $this->assertSame($existing->id, PluginOrder::sole()->customer_id);
    }

    /** טופס חסר נדחה עם הסבר, ולא נפתחת הזמנה. */
    public function test_a_form_without_consent_or_an_email_does_not_open_an_order(): void
    {
        Http::fake();

        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id, 'name' => 'יוסי', 'email' => 'not-an-email', 'terms' => '',
        ])->assertSessionHasErrors(['email', 'terms']);

        $this->assertSame(0, PluginOrder::count());
        Http::assertNothingSent();
    }

    /*
    | ----------------------------------------------------------------
    | אחרי התשלום
    | ----------------------------------------------------------------
    */

    private function paidOrder(): PluginOrder
    {
        $this->fakeCardcom();

        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id, 'name' => 'יוסי כהן', 'email' => 'yossi@example.co.il', 'terms' => '1',
        ]);

        $order = PluginOrder::sole();
        $order->charge->update(['status' => ChargeStatus::Succeeded, 'charged_at' => now()]);

        return $order->fresh();
    }

    /** חיוב שהצליח מנפיק רישיון, שולח אותו ופותח את המנוי שיחדש. */
    public function test_a_successful_payment_issues_the_licence_and_opens_the_renewal(): void
    {
        Mail::fake();

        $order = $this->paidOrder();

        $this->assertTrue($order->isFulfilled());
        $this->assertSame(PluginOrder::PAID, $order->status);

        $license = $order->license;
        $this->assertSame(2, $license->sites_limit);
        $this->assertSame(now()->addYear()->toDateString(), $license->expires_at->toDateString());

        // המנוי נגבה בעוד שנה — התקופה הראשונה כבר שולמה.
        $subscription = Subscription::sole();
        $this->assertSame($subscription->id, $license->subscription_id);
        $this->assertSame(now()->addYear()->toDateString(), $subscription->next_charge_at->toDateString());

        Mail::assertQueued(LicenseKeyMail::class);
    }

    /**
     * וובהוק שנמסר פעמיים אינו מנפיק רישיון שני.
     *
     * ספקי סליקה חוזרים על מסירה, וההתאמה המאוחרת מסיימת חיוב שהוובהוק שלו אבד.
     * שני רישיונות על רכישה אחת הם שני מפתחות אצל הלקוח ותור תמיכה שלם.
     */
    public function test_a_payment_confirmed_twice_issues_one_licence(): void
    {
        Mail::fake();

        $order = $this->paidOrder();
        $order->charge->update(['charged_at' => now()->addMinute()]);
        app(PluginCheckout::class)->fulfil($order->charge->fresh());

        $this->assertSame(1, License::count());
        $this->assertSame(1, Subscription::count());
    }

    /** עמוד ההזמנה לפני שהאישור הגיע אומר את האמת — לא "נכשל" ולא "מוכן". */
    public function test_the_order_page_admits_it_is_still_waiting(): void
    {
        $this->fakeCardcom();
        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id, 'name' => 'יוסי', 'email' => 'y@example.co.il', 'terms' => '1',
        ]);

        $this->get(route('store.done', ['reference' => PluginOrder::sole()->reference]))
            ->assertOk()
            ->assertSee('המפתח בדרך')
            ->assertDontSee('הרכישה לא הושלמה');
    }

    /** ואחרי — מציע את ההורדה, ולא מציג את המפתח על מסך שנשמר בהיסטוריית הדפדפן. */
    public function test_the_order_page_offers_the_download_but_never_shows_the_key(): void
    {
        Mail::fake();
        Storage::fake('local');
        Storage::disk('local')->put('plugin-releases/1.0.0.zip', 'PK');

        PluginRelease::create([
            'plugin_product_id' => $this->product->id,
            'version' => '1.0.0',
            'zip_path' => 'plugin-releases/1.0.0.zip',
            'is_current' => true,
        ]);

        $order = $this->paidOrder();

        $this->get(route('store.done', ['reference' => $order->reference]))
            ->assertOk()
            ->assertSee('הרישיון שלך מוכן')
            ->assertSee('העותק היחיד של המפתח')
            ->assertSee(route('store.download', ['reference' => $order->reference]));

        $this->get(route('store.download', ['reference' => $order->reference]))
            ->assertOk()
            ->assertDownload('wc-store-enhancer-1.0.0.zip');
    }

    /** הזמנה שלא שולמה אינה מורידה דבר. */
    public function test_an_unpaid_order_cannot_download(): void
    {
        $this->fakeCardcom();
        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $this->plan->id, 'name' => 'יוסי', 'email' => 'y@example.co.il', 'terms' => '1',
        ]);

        $this->get(route('store.download', ['reference' => PluginOrder::sole()->reference]))
            ->assertStatus(403);
    }

    /**
     * רכישת מסלול "לתמיד בלי עדכונים" — רישיון בלי תפוגה ובלי מנוי.
     *
     * זה מוצר שלישי, לא הנחה: הלקוח משלם פעם אחת, מקבל את התוסף לתמיד, ואינו
     * מקבל גרסאות חדשות. רישיון שהיה פג מיד היה מדווח לו שהתוסף שקנה שבור.
     */
    public function test_buying_a_perpetual_plan_without_updates_issues_a_licence_that_never_expires(): void
    {
        Mail::fake();

        $perpetual = $this->plan([
            'name' => 'רכישה לתמיד', 'price_agorot' => 60000,
            'billing_interval' => null, 'updates_months' => null, 'sites_limit' => 1,
        ]);

        $this->fakeCardcom();
        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $perpetual->id, 'name' => 'יוסי', 'email' => 'y@example.co.il', 'terms' => '1',
        ]);

        $order = PluginOrder::sole();
        $order->charge->update(['status' => ChargeStatus::Succeeded, 'charged_at' => now()]);

        $license = $order->fresh()->license;

        $this->assertNull($license->expires_at);
        $this->assertFalse($license->includesUpdates());
        $this->assertNull($license->subscription_id);
        $this->assertSame(0, Subscription::count());
    }

    /** ומסלול "לתמיד + שנה עדכונים" — תפוגה בעוד שנה, ועדיין בלי מנוי. */
    public function test_a_one_off_plan_with_a_year_of_updates_expires_in_a_year_without_a_subscription(): void
    {
        Mail::fake();

        $plan = $this->plan([
            'name' => 'לתמיד + שנה עדכונים', 'price_agorot' => 80000,
            'billing_interval' => null, 'updates_months' => 12,
        ]);

        $this->fakeCardcom();
        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $plan->id, 'name' => 'יוסי', 'email' => 'y@example.co.il', 'terms' => '1',
        ]);

        $order = PluginOrder::sole();
        $order->charge->update(['status' => ChargeStatus::Succeeded, 'charged_at' => now()]);

        $license = $order->fresh()->license;

        $this->assertSame(now()->addYear()->toDateString(), $license->expires_at->toDateString());
        $this->assertTrue($license->includesUpdates());
        $this->assertSame(0, Subscription::count());
    }

    /** מסלול של תוסף אחר אינו נקנה דרך העמוד הזה. */
    public function test_a_plan_belonging_to_another_plugin_cannot_be_bought_here(): void
    {
        Http::fake();

        $other = PluginProduct::create(['slug' => 'other', 'name' => 'אחר', 'is_active' => true]);
        $otherPlan = PluginPlan::create([
            'plugin_product_id' => $other->id, 'name' => 'זול', 'price_agorot' => 100,
            'sites_limit' => 1, 'is_active' => true,
        ]);

        $this->post(route('store.buy', ['slug' => 'wc-store-enhancer']), [
            'plan' => $otherPlan->id, 'name' => 'יוסי', 'email' => 'y@example.co.il', 'terms' => '1',
        ])->assertSessionHasErrors('plan');

        $this->assertSame(0, PluginOrder::count());
    }

    /** ומספר הזמנה שאינו קיים אינו מגלה דבר. */
    public function test_an_unknown_reference_reveals_nothing(): void
    {
        $this->get(route('store.done', ['reference' => 'made-up']))->assertNotFound();
    }

    /** חיוב רגיל של המערכת אינו הופך בטעות לרכישה. */
    public function test_an_ordinary_charge_is_not_mistaken_for_a_purchase(): void
    {
        $customer = Customer::factory()->create();

        Charge::create([
            'customer_id' => $customer->id,
            'amount_agorot' => 10000, 'vat_agorot' => 1800, 'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded, 'attempt_number' => 1,
            'description' => 'חיוב אחר',
            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
        ]);

        $this->assertSame(0, License::count());
    }
}
