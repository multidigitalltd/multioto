<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * מספר מנהל נוסף לאותו אתר — זול יותר ממנוי מלא, ומחויב בפועל.
 *
 * המוצר נמכר לפי אתר ומשמש לפי אדם, וזה לא אותו מספר: לחנות יש בעלים ומנהלת
 * משרד, ולסוכנות יש את הלקוח ואת מי שבאמת מתחזק את האתר. עד עכשיו מספר שני
 * היה מייל אלינו, כלומר בפועל מספר אחד ללקוח.
 *
 * שני הכיוונים כאן יקרים באותה מידה: מספר שנוסף ולא מחויב הוא מקום בתשלום
 * שניתן בחינם עד שמישהו ישים לב, ומספר שהוסר וממשיך להיות מחויב הוא לקוח
 * שמגלה את זה בחשבונית.
 */
class SiteAgentExtraNumbersTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Customer $customer;

    private Subscription $subscription;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.vat_rate' => 0.18]);

        // Set the way the operator sets it: the overlay reverts this key on
        // every queued job, so a runtime config() would be true here and false
        // by the time anything reads it.
        Setting::put('siteagent.enabled', '1');
        SettingsServiceProvider::refreshFromDatabase();

        $this->plan = Plan::create([
            'name' => 'סוכן האתר',
            'price_agorot' => 14900,
            'extra_number_price_agorot' => 4900,
            'vat_applies' => true,
            'billing_interval' => 'monthly',
            'active' => true,
            'is_public' => true,
            'includes_site_agent' => true,
        ]);

        $this->customer = Customer::factory()->create(['email' => 'dana@example.com']);
        $this->site = Site::factory()->create(['customer_id' => $this->customer->id, 'domain' => 'dana-shop.co.il']);

        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'plan_id' => $this->plan->id,
            'site_id' => $this->site->id,
            'status' => SubscriptionStatus::Active,
            'price_agorot_override' => null,
        ]);

        SiteAgentSubscriber::create([
            'phone' => '972501111111',
            'customer_id' => $this->customer->id,
            'site_id' => $this->site->id,
            'verified_at' => now(),
        ]);
    }

    private function asCustomer(): self
    {
        $this->withSession(['portal.customer_id' => $this->customer->id]);

        return $this;
    }

    /*
    | ----------------------------------------------------------------
    | המחיר
    | ----------------------------------------------------------------
    */

    /**
     * מספר נוסף באמת מגיע לחיוב.
     *
     * זו הבדיקה שבלעדיה כל התכונה מדומה: אפשר להוסיף מספרים, המסך מראה מחיר,
     * והחיוב התקופתי נשאר בדיוק אותו סכום.
     */
    public function test_an_extra_number_actually_reaches_the_charge(): void
    {
        $this->assertSame(14900, $this->subscription->basePriceAgorot());

        $this->subscription->update(['agent_extra_numbers' => 2]);

        $this->assertSame(14900 + 9800, $this->subscription->fresh()->basePriceAgorot());
        // And through VAT, which is what the customer's card is actually asked
        // for — a price that is right before VAT and wrong after is still wrong.
        $this->assertSame(29146, $this->subscription->fresh()->totalChargeAgorot());
    }

    /**
     * מחיר שסוכם עם לקוח נשאר מחיר השירות, והמספרים מיתווספים עליו.
     *
     * מחיר שנאמר בשיחה הוא המחיר של השירות; מספרים שנוספו חודשים אחר כך לא היו
     * חלק מאותה שיחה.
     */
    public function test_an_agreed_price_does_not_swallow_the_extra_numbers(): void
    {
        $this->subscription->update(['price_agorot_override' => 10000, 'agent_extra_numbers' => 1]);

        $this->assertSame(14900, $this->subscription->fresh()->basePriceAgorot());
    }

    /**
     * מסלול שאינו מוכר מספרים נוספים אינו מחייב עליהם.
     *
     * null ו-0 הן תשובות שונות: "אין מחיר" אינו "בחינם".
     */
    public function test_a_plan_that_names_no_price_charges_nothing_for_them(): void
    {
        $this->plan->update(['extra_number_price_agorot' => null]);
        $this->subscription->update(['agent_extra_numbers' => 3]);

        $this->assertSame(14900, $this->subscription->fresh()->basePriceAgorot());
    }

    /*
    | ----------------------------------------------------------------
    | האזור האישי
    | ----------------------------------------------------------------
    */

    public function test_the_page_states_the_price_and_when_it_starts(): void
    {
        $this->asCustomer()->get(route('portal.site-agent'))
            ->assertOk()
            ->assertSee('57.82')
            // The sentence that decides whether the next invoice is a dispute.
            ->assertSee('יתווסף לחיוב מהמחזור הבא');
    }

    public function test_adding_a_number_binds_it_charges_for_it_and_sends_a_code(): void
    {
        Queue::fake([SendSiteAgentVerificationJob::class]);

        $this->asCustomer()->post(route('portal.site-agent.add'), [
            'site_id' => $this->site->id,
            'phone' => '052-7654321',
            'name' => 'רות',
            'confirm' => '1',
        ])->assertRedirect();

        $added = SiteAgentSubscriber::where('phone', '972527654321')->sole();
        $this->assertSame($this->site->id, $added->site_id);
        // Bound, not trusted: it can do nothing until the person holding it
        // answers the code.
        $this->assertNull($added->verified_at);

        $this->assertSame(1, $this->subscription->fresh()->agent_extra_numbers);
        Queue::assertPushed(SendSiteAgentVerificationJob::class);
    }

    /** ובלי האישור המפורש על התוספת לחיוב — לא. */
    public function test_a_number_is_not_added_without_confirming_the_charge(): void
    {
        $this->asCustomer()->post(route('portal.site-agent.add'), [
            'site_id' => $this->site->id,
            'phone' => '052-7654321',
        ])->assertSessionHasErrors('confirm');

        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);
    }

    /**
     * מספר שכבר מנהל את האתר אינו מכירה חדשה.
     *
     * הוספה עיוורת הייתה מעלה את המחיר בשנית על מנהל שכבר היה שם — וזה מתגלה
     * בחשבונית הבאה.
     */
    public function test_re_adding_the_same_number_does_not_raise_the_price_twice(): void
    {
        $this->asCustomer()->post(route('portal.site-agent.add'), [
            'site_id' => $this->site->id,
            'phone' => '050-1111111',
            'confirm' => '1',
        ])->assertSessionHasErrors('phone');

        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);
    }

    /** הסרה מפסיקה גם את התשלום. */
    public function test_removing_a_number_releases_the_seat(): void
    {
        $this->subscription->update(['agent_extra_numbers' => 1]);

        $extra = SiteAgentSubscriber::create([
            'phone' => '972527654321',
            'customer_id' => $this->customer->id,
            'site_id' => $this->site->id,
            'verified_at' => now(),
        ]);

        $this->asCustomer()->post(route('portal.site-agent.revoke', ['subscriber' => $extra]))
            ->assertRedirect();

        $this->assertNotNull($extra->fresh()->revoked_at);
        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);
    }

    /**
     * אבל המספר הראשון כלול במסלול, ולכן הסרתו אינה יורדת מתחת לאפס.
     *
     * מונה שיורד מתחת לאפס הופך לזיכוי שמקטין את החשבונית מתחת למחיר המסלול.
     */
    public function test_the_seat_count_never_goes_below_zero(): void
    {
        $first = SiteAgentSubscriber::sole();

        $this->asCustomer()->post(route('portal.site-agent.revoke', ['subscriber' => $first]))
            ->assertRedirect();

        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);
        $this->assertSame(14900, $this->subscription->fresh()->basePriceAgorot());
    }

    /**
     * המונה נספר מהמציאות, ולא מונמך בעיוורון.
     *
     * מספרים שהצוות חיבר ממסך ההפעלה אינם מעלים את המונה — המסך ההוא מעולם לא
     * מכר מקומות. לקוח כזה מגיע לאזור האישי עם שלושה מנהלים ומונה שאומר אפס,
     * ולחיצה אחת על "הסרה" הייתה משאירה אותו שם: שני מנהלים עובדים, תשלום על
     * אחד. תת-גבייה שאיש לא היה מבחין בה, על שורה שנראית עקבית לגמרי.
     *
     * ספירה מחדש מיישרת את זה ברגע שנוגעים בו.
     */
    public function test_the_count_is_taken_from_reality_rather_than_nudged(): void
    {
        // Two more managers, bound the way the team screen binds them: no
        // counter moved, so the subscription still says nobody is paid for.
        foreach (['972527654321', '972533333333'] as $phone) {
            SiteAgentSubscriber::create([
                'phone' => $phone,
                'customer_id' => $this->customer->id,
                'site_id' => $this->site->id,
                'verified_at' => now(),
            ]);
        }

        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);

        $this->asCustomer()->post(route('portal.site-agent.revoke', [
            'subscriber' => SiteAgentSubscriber::where('phone', '972533333333')->sole(),
        ]))->assertRedirect();

        // Two managers left, one of them included: one paid seat, not zero.
        $this->assertSame(1, $this->subscription->fresh()->agent_extra_numbers);
        $this->assertSame(14900 + 4900, $this->subscription->fresh()->basePriceAgorot());
    }

    /**
     * מספר שנוסף לאתר אחד אינו מייקר את המנוי של אתר אחר.
     *
     * המוצר נמכר לפי אתר, ולכל אתר מנוי משלו. חיוב שנרשם על המנוי הלא נכון הוא
     * חשבונית שהלקוח אינו יכול להתאים לשום דבר.
     */
    public function test_a_number_raises_the_price_of_its_own_sites_subscription(): void
    {
        $otherSite = Site::factory()->create(['customer_id' => $this->customer->id, 'domain' => 'second.co.il']);
        $otherSubscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'plan_id' => $this->plan->id,
            'site_id' => $otherSite->id,
            'status' => SubscriptionStatus::Active,
            'price_agorot_override' => null,
        ]);

        SiteAgentSubscriber::create([
            'phone' => '972503333333',
            'customer_id' => $this->customer->id,
            'site_id' => $otherSite->id,
            'verified_at' => now(),
        ]);

        $this->asCustomer()->post(route('portal.site-agent.add'), [
            'site_id' => $otherSite->id,
            'phone' => '052-7654321',
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertSame(1, $otherSubscription->fresh()->agent_extra_numbers);
        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);
    }

    /**
     * לקוח אחד אינו נוגע במספרים של לקוח אחר.
     *
     * הכל נשען על הלקוח שנפתר מה-session, אף פעם לא על מזהה שבכתובת.
     */
    public function test_one_customer_cannot_touch_another_customers_number(): void
    {
        $other = Customer::factory()->create();
        $otherSite = Site::factory()->create(['customer_id' => $other->id]);
        $theirs = SiteAgentSubscriber::create([
            'phone' => '972539999999',
            'customer_id' => $other->id,
            'site_id' => $otherSite->id,
            'verified_at' => now(),
        ]);

        $this->asCustomer()->post(route('portal.site-agent.revoke', ['subscriber' => $theirs]))
            ->assertNotFound();

        $this->assertNull($theirs->fresh()->revoked_at);
    }

    /** ואי אפשר לקשור מספר לאתר של מישהו אחר. */
    public function test_a_number_cannot_be_bound_to_somebody_elses_site(): void
    {
        $otherSite = Site::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $this->asCustomer()->post(route('portal.site-agent.add'), [
            'site_id' => $otherSite->id,
            'phone' => '052-7654321',
            'confirm' => '1',
        ])->assertSessionHasErrors('site_id');

        $this->assertSame(0, SiteAgentSubscriber::where('site_id', $otherSite->id)->count());
    }

    /**
     * מנוי שאינו פעיל אינו גדל.
     *
     * הוספת מספר ללקוח בפיגור מגדילה את החוב של מי שהשירות שלו כבר כבוי.
     */
    public function test_a_lapsed_subscription_does_not_grow(): void
    {
        $this->subscription->update(['status' => SubscriptionStatus::PastDue]);

        $this->asCustomer()->post(route('portal.site-agent.add'), [
            'site_id' => $this->site->id,
            'phone' => '052-7654321',
            'confirm' => '1',
        ])->assertSessionHasErrors('phone');

        $this->assertSame(0, $this->subscription->fresh()->agent_extra_numbers);
    }

    /**
     * ולקוח שהמנוי שלו נפסק עדיין מגיע למסך — ורואה שם למה.
     *
     * זה בדיוק הלקוח שצריך את העמוד הזה: הסוכן שתק, והוא בא לברר אם משהו נשבר.
     */
    public function test_a_lapsed_customer_still_reaches_the_page_and_is_told_why(): void
    {
        $this->subscription->update(['status' => SubscriptionStatus::Suspended]);

        $this->asCustomer()->get(route('portal.site-agent'))
            ->assertOk()
            ->assertSee('המנוי אינו פעיל כרגע')
            // Said every time: the first thing a business owner fears is that
            // something of theirs was switched off.
            ->assertSee('האתר עצמו ממשיך לעבוד כרגיל');
    }
}
