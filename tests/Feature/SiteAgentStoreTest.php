<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentOrder;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteInstallation;
use App\Models\Subscription;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * לקוח קונה את סוכן האתר לבד.
 *
 * אותה צורה כמו חנות התוספים ומאותה סיבה: **הקונה עוזב.** הוא עובר לעמוד
 * הסליקה, ומה שחוזר הוא וובהוק לתהליך שאין לו דפדפן. לכן ההזמנה נכתבת לפני
 * שהוא הולך, ושום דבר לא ניתן לפני שהכסף הגיע — לא המנוי, לא האתר ולא הקישור
 * בין המספר לאתר.
 *
 * ההבדל מהתוספים הוא מה שנמכר: לא קובץ אלא שירות שעונה לטלפון. המבחן שחוזר כאן
 * שוב ושוב הוא לכן אחד — האם הרכישה מסתיימת במשהו שעובד, או במסך שנראה תקין
 * ובטלפון ששותק.
 */
class SiteAgentStoreTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.vat_rate' => 0.18]);

        // Through the settings store rather than config(), because that is how
        // the operator sets them AND because the overlay reverts these keys to
        // their config-file defaults every time it is re-applied — which happens
        // on every queued job. A runtime config() here survives the first
        // purchase and is blank by the second, which is a test that fails for a
        // reason that has nothing to do with what it is testing.
        foreach ([
            'siteagent.enabled' => '1',
            // The readiness gate in front of the sales page: without these the
            // product cannot send a code, and selling into that means taking
            // money for a number that never beeps.
            'siteagent.phone_number_id' => '1234',
            'siteagent.token' => 'wa-token',
            'siteagent.app_secret' => 'app-secret',
            'siteagent.verify_token' => 'verify',
            'siteagent.template_verification' => 'md_verification',
            'siteagent.template_paused' => 'md_paused',
            'siteagent.template_resumed' => 'md_resumed',
        ] as $key => $value) {
            Setting::put($key, $value);
        }

        SettingsServiceProvider::refreshFromDatabase();

        $this->plan = Plan::create([
            'name' => 'סוכן האתר — חודשי',
            'price_agorot' => 14900,
            'extra_number_price_agorot' => 4900,
            'vat_applies' => true,
            'billing_interval' => 'monthly',
            'active' => true,
            'is_public' => true,
            'includes_site_agent' => true,
        ]);
    }

    private function fakeCardcom(): void
    {
        Http::fake(['*' => Http::response([
            'ResponseCode' => 0,
            'Url' => 'https://secure.cardcom.solutions/pay/abc',
            'LowProfileId' => 'lp-1',
        ])]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function buy(array $overrides = []): TestResponse
    {
        return $this->post(route('store.agent.buy'), array_merge([
            'plan' => $this->plan->id,
            'name' => 'דנה כהן',
            'email' => 'dana@example.com',
            'phone' => '050-1234567',
            'domain' => 'dana-shop.co.il',
            'install_mode' => SiteAgentOrder::INSTALL_SELF,
            'terms' => '1',
        ], $overrides));
    }

    /** Pay for an order the way the webhook eventually does. */
    private function pay(SiteAgentOrder $order, string $transactionId = 'tx-1'): void
    {
        Charge::find($order->charge_id)->update([
            'status' => ChargeStatus::Succeeded,
            'cardcom_transaction_id' => $transactionId,
        ]);
    }

    /*
    | ----------------------------------------------------------------
    | עמוד המכירה
    | ----------------------------------------------------------------
    */

    public function test_the_page_shows_the_price_the_buyer_will_actually_pay(): void
    {
        $this->get(route('store.agent'))
            ->assertOk()
            ->assertSee('175.82')   // 149 ₪ + מע״מ
            ->assertSee('57.82');   // מספר נוסף: 49 ₪ + מע״מ
    }

    /**
     * מסלול פרטי אינו נמכר לכל מי שמגיע לעמוד.
     *
     * בטבלת המסלולים יש מחירים שסוכמו עם לקוח אחד. מסלול שנמכר רק מפני שהוא
     * פעיל היה מפרסם אותם — ומאפשר לכל אחד לקנות במחיר הנמוך ביותר שבהם.
     */
    public function test_a_private_plan_is_neither_shown_nor_purchasable(): void
    {
        $private = Plan::create([
            'name' => 'מחיר מיוחד ללקוח ותיק',
            'price_agorot' => 5000,
            'vat_applies' => true,
            'billing_interval' => 'monthly',
            'active' => true,
            'is_public' => false,
            'includes_site_agent' => true,
        ]);

        $this->get(route('store.agent'))->assertOk()->assertDontSee('מחיר מיוחד ללקוח ותיק');

        $this->buy(['plan' => $private->id])->assertSessionHasErrors('plan');
        $this->assertSame(0, SiteAgentOrder::count());
    }

    /**
     * מוצר שאינו יכול לשלוח קוד אימות אינו נמכר בכלל.
     *
     * ההודעה הראשונה ללקוח חדש היא הקוד, ובלי תבנית מאושרת מטא דוחה אותה. עמוד
     * מכירה שפתוח במצב הזה גובה כסף עבור מספר שלעולם לא יצלצל.
     */
    public function test_the_page_closes_when_the_product_cannot_send_a_code(): void
    {
        Setting::put('siteagent.template_verification', '');
        SettingsServiceProvider::refreshFromDatabase();

        $this->get(route('store.agent'))->assertNotFound();
        $this->buy()->assertNotFound();
    }

    /*
    | ----------------------------------------------------------------
    | הרכישה
    | ----------------------------------------------------------------
    */

    public function test_a_purchase_records_the_order_and_sends_the_buyer_to_pay(): void
    {
        $this->fakeCardcom();

        $this->buy()->assertRedirect('https://secure.cardcom.solutions/pay/abc');

        $order = SiteAgentOrder::sole();
        $this->assertSame(SiteAgentOrder::PENDING, $order->status);
        $this->assertSame('dana-shop.co.il', $order->domain);
        // Normalised on the way in, to the form the inbound webhook will present
        // the number in — otherwise the first message from the customer matches
        // no binding at all.
        $this->assertSame('972501234567', $order->manager_phone);
        $this->assertSame(17582, $order->total_agorot);
        $this->assertNotNull($order->charge_id);
    }

    /**
     * הדבר החשוב ביותר כאן: כלום לא ניתן לפני שהכסף הגיע.
     *
     * מנוי שנפתח בקופה הוא שירות שנשאר בידי כל מי שפתח את עמוד התשלום והלך.
     */
    public function test_nothing_is_granted_before_the_money_arrives(): void
    {
        $this->fakeCardcom();
        $this->buy();

        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, SiteAgentSubscriber::count());
        $this->assertSame(0, Site::count());
    }

    /** ועמוד ההמתנה אינו אומר לקונה ששילם שהוא לא שילם. */
    public function test_the_waiting_page_never_tells_a_payer_that_they_did_not_pay(): void
    {
        $this->fakeCardcom();
        $this->buy();

        $this->get(route('store.agent.done', ['reference' => SiteAgentOrder::sole()->reference]))
            ->assertOk()
            ->assertSee('ממתינים לאישור מחברת הסליקה')
            ->assertSee('אין צורך לשלם שוב')
            ->assertDontSee('התשלום נכשל');
    }

    /*
    | ----------------------------------------------------------------
    | הכסף הגיע
    | ----------------------------------------------------------------
    */

    public function test_paying_opens_the_subscription_binds_the_number_and_sends_the_code(): void
    {
        Queue::fake([SendSiteAgentVerificationJob::class]);
        $this->fakeCardcom();
        $this->buy();

        $order = SiteAgentOrder::sole();
        $this->pay($order);

        $order->refresh();
        $this->assertSame(SiteAgentOrder::PAID, $order->status);

        $subscription = Subscription::sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($this->plan->id, $subscription->plan_id);

        $subscriber = SiteAgentSubscriber::sole();
        $this->assertSame('972501234567', $subscriber->phone);
        // Bound but NOT verified: the code has been sent and nothing may be done
        // with the site until the person holding the phone answers it.
        $this->assertNull($subscriber->verified_at);

        Queue::assertPushed(SendSiteAgentVerificationJob::class);
    }

    /**
     * האתר נוצר מנותק, ונאמר כך.
     *
     * התוסף עדיין לא מותקן. "מחובר" על אתר ששום דבר לא יכול להגיע אליו הוא
     * סוכן שמקבל הוראות שאין לו ידיים לבצע.
     */
    public function test_the_site_is_created_disconnected(): void
    {
        $this->fakeCardcom();
        $this->buy();
        $this->pay(SiteAgentOrder::sole());

        $site = Site::sole();
        $this->assertSame('dana-shop.co.il', $site->domain);
        $this->assertFalse((bool) $site->mcp_enabled);
    }

    /** ורק אז מוצגים קודי ההתקנה. */
    public function test_the_connection_codes_appear_only_after_payment(): void
    {
        $this->fakeCardcom();
        $this->buy();
        $order = SiteAgentOrder::sole();

        $this->get(route('store.agent.done', ['reference' => $order->reference]))
            ->assertOk()
            ->assertDontSee('מפתח MCP');

        $this->pay($order);

        $this->get(route('store.agent.done', ['reference' => $order->reference]))
            ->assertOk()
            ->assertSee('מפתח MCP')
            ->assertSee('טוקן עדכונים')
            ->assertSee(Site::sole()->fresh()->mcp_secret);
    }

    /** וקובץ התוסף אינו יורד על הזמנה שלא שולמה. */
    public function test_the_plugin_download_refuses_an_unpaid_order(): void
    {
        $this->fakeCardcom();
        $this->buy();

        $this->get(route('store.agent.plugin', ['reference' => SiteAgentOrder::sole()->reference]))
            ->assertForbidden();
    }

    /**
     * לקוח חוזר אינו הופך ללקוח שני, ואינו מקבל מנוי שני.
     *
     * שני מנויים לאותו שירות פירושם חיוב כפול ושתי סולמות גבייה על אותו אדם.
     */
    public function test_a_returning_customer_gets_one_customer_record_and_one_subscription(): void
    {
        $this->fakeCardcom();
        $this->buy();
        $this->pay(SiteAgentOrder::sole());

        $this->buy(['domain' => 'second-site.co.il']);
        $this->pay(SiteAgentOrder::query()->latest('id')->firstOrFail(), 'tx-2');

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Subscription::count());
        // Both sites, and both numbers' bindings — only the billing is shared.
        $this->assertSame(2, Site::count());
        $this->assertSame(2, SiteAgentSubscriber::count());
    }

    /**
     * וובהוק שהגיע פעמיים אינו קונה פעמיים.
     *
     * אותו charge נשמר שוב — בגלל reconciliation, בגלל ניסיון חוזר — וזה חייב
     * לא לפתוח מנוי שני על אותו תשלום אחד.
     */
    public function test_the_same_payment_confirmed_twice_grants_the_service_once(): void
    {
        $this->fakeCardcom();
        $this->buy();
        $order = SiteAgentOrder::sole();

        $this->pay($order);
        Charge::find($order->charge_id)->update(['cardcom_transaction_id' => 'tx-1-again']);

        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, SiteAgentSubscriber::count());
    }

    /*
    | ----------------------------------------------------------------
    | "תתקינו לי" — הגישה שהלקוח מוסר
    | ----------------------------------------------------------------
    */

    public function test_asking_us_to_install_opens_a_request_and_asks_for_access(): void
    {
        $this->fakeCardcom();
        $this->buy(['install_mode' => SiteAgentOrder::INSTALL_BY_US]);
        $order = SiteAgentOrder::sole();
        $this->pay($order);

        $installation = SiteInstallation::sole();
        $this->assertSame(SiteInstallation::AWAITING_ACCESS, $installation->state);

        $this->get(route('store.agent.done', ['reference' => $order->reference]))
            ->assertOk()
            ->assertSee('נשאר רק לתת לנו גישה')
            // The recommended option is the weaker one, and it is recommended in
            // writing rather than merely listed first.
            ->assertSee('קישור התחברות זמני')
            // Somebody who did not ask us to install is not shown a box asking
            // for their WordPress password.
            ->assertSee('הדרך המומלצת');
    }

    public function test_the_handover_is_stored_encrypted_and_never_shown_back(): void
    {
        $this->fakeCardcom();
        $this->buy(['install_mode' => SiteAgentOrder::INSTALL_BY_US]);
        $order = SiteAgentOrder::sole();
        $this->pay($order);

        $this->post(route('store.agent.access', ['reference' => $order->reference]), [
            'access_method' => SiteInstallation::ACCESS_TEMP_LOGIN,
            'access_secret' => 'https://dana-shop.co.il/?tml=SECRETLINK',
            'access_note' => 'עדיף בבוקר',
        ])->assertRedirect();

        $installation = SiteInstallation::sole();
        $this->assertSame(SiteInstallation::READY, $installation->state);
        $this->assertSame('https://dana-shop.co.il/?tml=SECRETLINK', $installation->access_secret);

        // Encrypted at rest: a database dump taken to debug something must not
        // be a list of customers' admin logins.
        $raw = (string) DB::table('site_installations')
            ->where('id', $installation->id)->value('access_secret');
        $this->assertStringNotContainsString('SECRETLINK', $raw);

        // Hidden from every array copy of the model, so it cannot reach a log by
        // being somewhere a whole model was dumped.
        $this->assertArrayNotHasKey('access_secret', $installation->toArray());

        // And the page the customer returns to does not paint it back onto a
        // screen that may be shared or screenshotted.
        $this->get(route('store.agent.done', ['reference' => $order->reference]))
            ->assertOk()
            ->assertSee('קיבלנו את הגישה')
            ->assertDontSee('SECRETLINK');
    }

    /** גישה אינה נמסרת על הזמנה שלא שולמה, ולא על הזמנה שלא ביקשה התקנה. */
    public function test_the_handover_is_refused_when_it_was_never_asked_for(): void
    {
        $this->fakeCardcom();
        $this->buy(['install_mode' => SiteAgentOrder::INSTALL_SELF]);
        $order = SiteAgentOrder::sole();
        $this->pay($order);

        $this->post(route('store.agent.access', ['reference' => $order->reference]), [
            'access_method' => SiteInstallation::ACCESS_TEMP_LOGIN,
            'access_secret' => 'https://example.test/?tml=x',
        ])->assertForbidden();

        $this->assertSame(0, SiteInstallation::count());
    }

    /*
    | ----------------------------------------------------------------
    | החיבור בפועל
    | ----------------------------------------------------------------
    */

    /**
     * האתר מתחבר לבד כשהתוסף אומר שלום בפעם הראשונה.
     *
     * זה הפער היחיד שנשאר ברכישה עצמית: הלקוח שילם, התקין והדביק את הקודים —
     * ואז כלום, כי mcp_enabled הוא מתג שאיש צוות מסמן והלקוח אינו יודע שקיים.
     * הסוכן היה עונה "האתר אינו מחובר" למי שעשה הכל נכון.
     */
    public function test_the_site_connects_itself_when_the_plugin_first_checks_in(): void
    {
        $this->fakeCardcom();
        $this->buy();
        $this->pay(SiteAgentOrder::sole());

        $site = Site::sole();
        $token = $site->ensureAgentCredentials()['update_token'];

        $this->withToken($token)
            ->getJson(route('agent.plugin.update', ['version' => '1.5.0']))
            ->assertOk();

        $this->assertTrue((bool) $site->fresh()->mcp_enabled);
    }

    /**
     * אבל אתר שאיש צוות ניתק במכוון אינו מתחבר בחזרה מעצמו.
     *
     * אותו מתג הוא גם הדרך לנתק אתר בכוונה, וסריקה שמדליקה אותו בחזרה הופכת
     * החלטה של אדם להצעה.
     */
    public function test_a_site_somebody_disconnected_on_purpose_stays_disconnected(): void
    {
        $this->fakeCardcom();
        $this->buy();
        $this->pay(SiteAgentOrder::sole());

        $site = Site::sole();
        $token = $site->ensureAgentCredentials()['update_token'];

        // It has been in contact before, and a person switched it off since.
        $site->forceFill(['mcp_enabled' => false, 'mcp_last_seen_at' => now()->subDay()])->save();

        $this->withToken($token)
            ->getJson(route('agent.plugin.update', ['version' => '1.5.0']))
            ->assertOk();

        $this->assertFalse((bool) $site->fresh()->mcp_enabled);
    }

    /** ואתר שאיש לא קנה עליו את השירות אינו מתחבר מעצמו בכלל. */
    public function test_a_site_with_no_paid_order_never_connects_itself(): void
    {
        $site = Site::factory()->create(['mcp_enabled' => false]);
        $token = $site->ensureAgentCredentials()['update_token'];

        $this->withToken($token)
            ->getJson(route('agent.plugin.update', ['version' => '1.5.0']))
            ->assertOk();

        $this->assertFalse((bool) $site->fresh()->mcp_enabled);
    }
}
