<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSiteAgent;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\SiteAgentSubscribersRelationManager;
use App\Filament\Widgets\SiteAgentOverview;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Services\SiteAgent\SiteAgentProduct;
use App\Services\System\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * המוצר שאנחנו מוכרים, כפי שהוא נראה מלוח הבקרה.
 *
 * הסוכן נבנה במלואו — ערוץ, אימות, גבייה, יומן — ובכל זאת לא היה בפאנל שום
 * מקום שאומר שהוא קיים: לא כמה משלמים עליו, לא כמה הוא מכניס, ובעיקר לא
 * שהוא כבוי או חסר תבניות. הבדיקות כאן הן על ההבדל הזה, ורובן על המקרה
 * היחיד שבאמת עולה כסף — מוצר שדולק, נראה תקין, ושום הודעה שלו לא נשלחת.
 */
class SiteAgentProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.app_secret' => 'app-secret',
            'siteagent.whatsapp.verify_token' => 'verify-me',
            'siteagent.whatsapp.token' => 'permanent-token',
            'siteagent.whatsapp.phone_number_id' => '123456',
            'siteagent.whatsapp.templates.verification' => 'site_agent_code',
            'siteagent.whatsapp.templates.service_paused' => 'site_agent_paused',
            'siteagent.whatsapp.templates.service_resumed' => 'site_agent_resumed',
        ]);
    }

    private function product(): SiteAgentProduct
    {
        return app(SiteAgentProduct::class);
    }

    private function connectedSite(?Customer $customer = null): Site
    {
        $customer ??= Customer::factory()->create();

        return Site::factory()->create([
            'customer_id' => $customer->id,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp',
        ]);
    }

    private function subscribe(Customer $customer, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true, 'price_agorot' => 9900])->id,
            'status' => $status,
        ]);
    }

    private function bind(Site $site, array $attributes = []): SiteAgentSubscriber
    {
        return SiteAgentSubscriber::create(array_merge([
            'phone' => '9725012345'.random_int(10, 99),
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | מה חסר כדי שהמוצר יעבוד
    |--------------------------------------------------------------------------
    */

    public function test_a_fully_configured_product_reports_nothing_missing(): void
    {
        $this->assertTrue($this->product()->ready());
        $this->assertSame([], $this->product()->missing());
    }

    /**
     * זה המקרה שכל המסך הזה קיים בשבילו.
     *
     * בלי שם תבנית מאושר, מטא דוחה כל הודעה שאנחנו מתחילים — וקוד האימות של
     * לקוח חדש הוא בדיוק הודעה כזאת. שום מסך אינו מראה שגיאה, רשימת המנויים
     * נראית תקינה לגמרי, והלקוח פשוט מחזיק טלפון שלא צלצל.
     */
    public function test_a_missing_template_is_named_as_the_reason_a_new_customer_never_gets_a_code(): void
    {
        config(['siteagent.whatsapp.templates.verification' => '']);

        $missing = $this->product()->missing();

        $this->assertFalse($this->product()->ready());
        $this->assertSame(['template_verification'], array_column($missing, 'key'));
        $this->assertStringContainsString('לא יקבל קוד', $missing[0]['detail']);
    }

    public function test_every_requirement_is_reported_when_nothing_is_configured(): void
    {
        config([
            'siteagent.enabled' => false,
            'siteagent.whatsapp.app_secret' => '',
            'siteagent.whatsapp.verify_token' => '',
            'siteagent.whatsapp.token' => '',
            'siteagent.whatsapp.phone_number_id' => '',
            'siteagent.whatsapp.templates.verification' => '',
            'siteagent.whatsapp.templates.service_paused' => '',
            'siteagent.whatsapp.templates.service_resumed' => '',
        ]);

        $this->assertCount(7, $this->product()->missing());
    }

    /*
    |--------------------------------------------------------------------------
    | מי משתמש, ומי משלם
    |--------------------------------------------------------------------------
    */

    /**
     * המספר שמחזיק את השם "מושהה": מאומת, לא בוטל, והלקוח הפסיק לשלם.
     *
     * אף אחד לא עשה משהו שגוי, שום דבר לא נשבר, והסוכן פשוט השתתק אצל מי
     * שהשתמש בו אתמול. זאת שיחת הטלפון שעדיף ליזום מאשר לקבל.
     */
    public function test_a_number_whose_customer_stopped_paying_is_counted_as_paused(): void
    {
        $paying = Customer::factory()->create();
        $this->bind($this->connectedSite($paying));
        $this->subscribe($paying);

        $lapsed = Customer::factory()->create();
        $this->bind($this->connectedSite($lapsed));
        $this->subscribe($lapsed, SubscriptionStatus::PastDue);

        $numbers = $this->product()->numbers();

        $this->assertSame(1, $numbers['active']);
        $this->assertSame(1, $numbers['paused']);
    }

    public function test_an_unverified_number_is_pending_and_a_revoked_one_is_neither(): void
    {
        $customer = Customer::factory()->create();
        $site = $this->connectedSite($customer);
        $this->subscribe($customer);

        $this->bind($site, ['verified_at' => null]);
        $this->bind($site, ['revoked_at' => now(), 'revoked_reason' => 'עזב את החברה']);

        $numbers = $this->product()->numbers();

        $this->assertSame(0, $numbers['active']);
        $this->assertSame(1, $numbers['pending']);
        $this->assertSame(1, $numbers['revoked']);
        // A revoked number is not "paused" — nobody is waiting for it to come
        // back, and counting it as such would turn a closed account into a
        // standing task.
        $this->assertSame(0, $numbers['paused']);
    }

    /**
     * ניסיון נחשב מנוי ואינו נחשב הכנסה, והשניים אמורים לא להסכים.
     */
    public function test_a_trial_entitles_without_being_counted_as_income(): void
    {
        $customer = Customer::factory()->create();
        $this->bind($this->connectedSite($customer));
        $this->subscribe($customer, SubscriptionStatus::Trialing);

        $money = $this->product()->money();

        $this->assertSame(1, $money['subscribed']);
        $this->assertSame(0, $money['monthly_agorot']);
        $this->assertSame(1, $this->product()->numbers()['active']);
    }

    public function test_a_paying_subscription_is_counted_in_the_monthly_figure(): void
    {
        $customer = Customer::factory()->create(['vat_exempt' => true]);
        $this->bind($this->connectedSite($customer));
        $this->subscribe($customer);

        // VAT-exempt, so the figure is exactly the plan price and the test is
        // about the sum rather than about the tax rate of the day.
        $this->assertSame(9900, $this->product()->money()['monthly_agorot']);
    }

    /**
     * מסלול שנתי הוא חיוב אחד לשנה, ולא הכנסה חודשית.
     *
     * מסך ההפעלה מקבל כל מסלול פעיל שמסומן ככולל סוכן, שנתי בכלל זה. בלי
     * נירמול, לקוח שמשלם ₪1,200 בשנה היה מדווח כ-₪1,200 בחודש — אריח שמנפח
     * את הכנסת המוצר פי שתים עשרה, ונראה סביר לגמרי בזמן שהוא עושה את זה.
     */
    public function test_a_yearly_plan_is_divided_into_months_before_it_is_called_monthly(): void
    {
        $customer = Customer::factory()->create(['vat_exempt' => true]);
        $this->bind($this->connectedSite($customer));

        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create([
                'includes_site_agent' => true,
                'price_agorot' => 120000,
                'billing_interval' => BillingInterval::Yearly,
            ])->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertSame(10000, $this->product()->money()['monthly_agorot']);
    }

    /**
     * מנוי שאינו נגבה: ניסיון בלי כרטיס.
     *
     * הוא נופל בין הכיסאות של כל שאר המסכים — המתזמן מדלג עליו כי אין אסימון,
     * ומסכי החייבים מדלגים עליו כי הוא ניסיון — ולכן הוא נאמר כאן במפורש.
     */
    public function test_a_trial_with_no_card_is_reported_as_unbilled(): void
    {
        $customer = Customer::factory()->create();
        $this->bind($this->connectedSite($customer));
        $this->subscribe($customer, SubscriptionStatus::Trialing)->update(['token_id' => null]);

        $this->assertSame(1, $this->product()->money()['unbilled']);
    }

    public function test_requests_are_summarised_over_the_week_rather_than_the_day(): void
    {
        $customer = Customer::factory()->create();
        $site = $this->connectedSite($customer);
        $subscriber = $this->bind($site);

        $base = [
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $site->id,
            'customer_id' => $customer->id,
            'message' => 'תעדכן מחיר',
            'operation' => SiteAgentRequest::OP_PRICE,
        ];

        SiteAgentRequest::create($base + ['state' => SiteAgentRequest::APPLIED, 'applied_at' => now()->subDays(3)]);
        SiteAgentRequest::create($base + ['state' => SiteAgentRequest::APPLIED, 'applied_at' => now()->subDays(20)]);
        SiteAgentRequest::create($base + ['state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->addHour()]);
        // Aged out: still in AWAITING, but no "כן" typed today can confirm it.
        SiteAgentRequest::create($base + ['state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->subHour()]);

        $activity = $this->product()->activity();

        $this->assertSame(1, $activity['applied']);
        $this->assertSame(1, $activity['awaiting']);
    }

    /*
    |--------------------------------------------------------------------------
    | לוח הבקרה
    |--------------------------------------------------------------------------
    */

    public function test_the_dashboard_shows_the_product_once_it_is_switched_on(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertTrue(SiteAgentOverview::canView());
    }

    /**
     * התקנה שלא מוכרת את המוצר לא מקבלת שורת אפסים כל בוקר.
     */
    public function test_an_install_that_does_not_sell_this_gets_no_tiles(): void
    {
        config(['siteagent.enabled' => false]);
        $this->actingAs(User::factory()->create());

        $this->assertFalse(SiteAgentOverview::canView());
    }

    /**
     * ...אבל מוצר שנמכר ואז כובה עדיין מוצג — שם דווקא חשוב לראות אותו.
     */
    public function test_a_switched_off_product_with_subscribers_is_still_shown(): void
    {
        config(['siteagent.enabled' => false]);
        $this->bind($this->connectedSite());
        $this->actingAs(User::factory()->create());

        $this->assertTrue(SiteAgentOverview::canView());
    }

    public function test_a_team_member_without_the_management_module_is_not_shown_the_product(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['support'],
        ]));

        $this->assertFalse(SiteAgentOverview::canView());
    }

    public function test_the_dashboard_says_out_loud_when_the_product_cannot_send(): void
    {
        config(['siteagent.whatsapp.templates.verification' => '']);
        $this->actingAs(User::factory()->create());

        Livewire::test(SiteAgentOverview::class)
            ->assertSee('חסר להפעלה')
            ->assertSee('תבנית קוד האימות');
    }

    /**
     * האריח לא שולח אנשים למסך שהם יקבלו בו 403.
     *
     * הווידג'ט פתוח למודול "ניהול" ומסך ההגדרות פתוח למנהלים בלבד. אריח בולט
     * שאומר "תקנו את זה" ומחזיר 403 גרוע מאריח שאינו מציע את עצמו: הקורא
     * מתבקש לפעול ואז נחסם, והתקלה האמיתית נקראת כבעיית הרשאות.
     */
    public function test_the_missing_configuration_tile_does_not_send_a_non_admin_to_a_403(): void
    {
        config(['siteagent.whatsapp.templates.verification' => '']);

        $agent = User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['management'],
        ]);

        $this->actingAs($agent);
        $this->assertFalse(ManageSiteAgent::canAccess());

        $forAgent = Livewire::test(SiteAgentOverview::class)
            ->assertSee('חסר להפעלה')
            ->assertSee('להגדרה נדרש מנהל')
            ->assertDontSee(ManageSiteAgent::getUrl(), escape: false);

        $this->assertNotNull($forAgent);

        // ...and an admin still gets the link, so the tile stays actionable
        // for whoever can act on it.
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(SiteAgentOverview::class)
            ->assertSee(ManageSiteAgent::getUrl(), escape: false)
            ->assertDontSee('להגדרה נדרש מנהל');
    }

    public function test_revenue_is_shown_only_to_whoever_may_see_money_elsewhere(): void
    {
        $customer = Customer::factory()->create();
        $this->bind($this->connectedSite($customer));
        $this->subscribe($customer);

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['management'],
        ]));

        Livewire::test(SiteAgentOverview::class)
            ->assertSee('מספרים פעילים')
            ->assertDontSee('הכנסה חודשית מהמוצר');
    }

    /*
    |--------------------------------------------------------------------------
    | תקינות המערכת
    |--------------------------------------------------------------------------
    */

    /**
     * The missing requirement here is the app secret rather than a template
     * name, and deliberately so: collecting the report re-applies the settings
     * overlay, which restores every template name from its stored value. The
     * secret is not one of those, so what this test arranges is still true by
     * the time the report is read.
     */
    public function test_a_product_that_is_on_but_cannot_send_is_reported_as_a_system_problem(): void
    {
        config(['siteagent.whatsapp.app_secret' => '']);

        $problem = collect(app(HealthReport::class)->problems())
            ->firstWhere('key', 'site_agent');

        $this->assertNotNull($problem);
        $this->assertSame(HealthReport::DEGRADED, $problem['status']);
    }

    /** שירות שאיש לא הדליק אינו שירות שהפסיק לעבוד. */
    public function test_a_switched_off_product_is_not_reported_as_broken(): void
    {
        config([
            'siteagent.enabled' => false,
            'siteagent.whatsapp.app_secret' => '',
        ]);

        $this->assertNull(
            collect(app(HealthReport::class)->problems())->firstWhere('key', 'site_agent')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | מסך ההגדרות
    |--------------------------------------------------------------------------
    */

    public function test_the_product_can_be_configured_without_a_deploy(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManageSiteAgent::class)
            ->fillForm([
                'siteagent' => [
                    'enabled' => true,
                    'phone_number_id' => '778899',
                    'token' => 'a-permanent-token',
                    'app_secret' => 'the-app-secret',
                    'verify_token' => 'the-verify-token',
                    'template_language' => 'he',
                    'template_verification' => 'code_v2',
                    'template_verification_copy_button' => true,
                    'template_paused' => 'paused_v2',
                    'template_resumed' => 'resumed_v2',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        SettingsServiceProvider::refreshFromDatabase();

        $this->assertSame('778899', config('siteagent.whatsapp.phone_number_id'));
        $this->assertSame('code_v2', config('siteagent.whatsapp.templates.verification'));
        $this->assertSame('the-app-secret', config('siteagent.whatsapp.app_secret'));
        $this->assertTrue(config('siteagent.enabled'));
    }

    /**
     * שדה סוד ריק פירושו "אל תשנה", לא "מחק".
     *
     * המסך לעולם אינו מציג סודות חזרה, ולכן ריק הוא המצב הרגיל של כל מי שנכנס
     * לשנות משהו אחר. אילו ריק היה מוחק, כל שמירה של שם תבנית הייתה מנתקת את
     * המספר מהמערכת בשקט.
     */
    public function test_saving_with_a_blank_secret_does_not_erase_the_stored_one(): void
    {
        Setting::put('siteagent.token', 'already-stored');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManageSiteAgent::class)
            ->fillForm(['siteagent' => ['template_paused' => 'paused_v3']])
            ->call('save');

        $this->assertSame('already-stored', Setting::map()['siteagent.token'] ?? null);
    }

    /**
     * שם תבנית שנמחק חייב להיעלם גם מתהליך שרץ מזמן.
     *
     * תבנית שכבר אינה מאושרת אצל מטא היא תבנית שכל הודעה תחתיה נדחית, ועובד
     * תור שממשיך להשתמש בשם הישן ימשיך לשלוח לשום מקום עד שיופעל מחדש.
     */
    public function test_clearing_a_template_name_reverts_it_rather_than_leaving_it_behind(): void
    {
        Setting::put('siteagent.template_paused', 'paused_old');
        SettingsServiceProvider::refreshFromDatabase();
        $this->assertSame('paused_old', config('siteagent.whatsapp.templates.service_paused'));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManageSiteAgent::class)
            ->fillForm(['siteagent' => ['template_paused' => '']])
            ->call('save');

        SettingsServiceProvider::refreshFromDatabase();

        $this->assertNotSame('paused_old', config('siteagent.whatsapp.templates.service_paused'));
    }

    /*
    |--------------------------------------------------------------------------
    | בכרטיס הלקוח
    |--------------------------------------------------------------------------
    */

    /**
     * הטאב מופיע רק אצל לקוח שבאמת משתמש במוצר.
     */
    public function test_the_customer_page_lists_the_numbers_that_can_change_their_site(): void
    {
        $customer = Customer::factory()->create();
        $site = $this->connectedSite($customer);
        $this->subscribe($customer);
        $subscriber = $this->bind($site);

        $this->actingAs(User::factory()->create());

        $this->assertTrue(SiteAgentSubscribersRelationManager::canViewForRecord($customer, ViewCustomer::class));

        $component = Livewire::test(SiteAgentSubscribersRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])->assertCanSeeTableRecords([$subscriber]);

        // The state badge is read off a flag the resource adds in its OWN
        // query, and these rows come from the relationship instead. Asserted on
        // the loaded row rather than looked for in the page, because every
        // state's wording also appears in the filter's options — finding the
        // text there would prove nothing about this row. Without the flag the
        // badge reads "אין מנוי פעיל" for a customer who is plainly paying.
        $this->assertTrue((bool) $component->instance()->getTableRecords()->first()->customer_subscribed);
    }

    public function test_a_customer_who_never_bought_the_product_gets_no_tab_for_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(SiteAgentSubscribersRelationManager::canViewForRecord(
            Customer::factory()->create(),
            ViewCustomer::class,
        ));
    }

    /**
     * מילוי אוטומטי של סיסמת הפאנל אינו נשמר כסוד.
     *
     * השדות האלה אינם type=password בדיוק כדי שדפדפנים לא ימלאו אותם אוטומטית,
     * וזה לא תמיד מספיק — במסך האינטגרציות זה כבר קרה. כאן המחיר גבוה יותר:
     * שדה הטוקן נשלח למטא ככותרת Bearer, כך ששמירה כזאת מוסרת לצד שלישי את
     * סיסמת הכניסה לפאנל.
     */
    public function test_an_autofilled_panel_password_is_never_stored_as_a_secret(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'password' => bcrypt('the-admins-own-password'),
        ]);

        $this->actingAs($admin);

        Livewire::test(ManageSiteAgent::class)
            ->fillForm(['siteagent' => ['token' => 'the-admins-own-password']])
            ->call('save');

        $this->assertArrayNotHasKey('siteagent.token', Setting::map());
    }

    /** אבל סוד אמיתי עדיין נשמר באותה שמירה. */
    public function test_a_real_secret_is_still_stored(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
            'password' => bcrypt('the-admins-own-password'),
        ]));

        Livewire::test(ManageSiteAgent::class)
            ->fillForm(['siteagent' => ['token' => 'EAAG-a-real-meta-token']])
            ->call('save');

        $this->assertSame('EAAG-a-real-meta-token', Setting::map()['siteagent.token'] ?? null);
    }

    public function test_the_settings_screen_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['management'],
        ]));

        $this->assertFalse(ManageSiteAgent::canAccess());
    }
}
