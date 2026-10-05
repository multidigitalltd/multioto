<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Providers\SettingsServiceProvider;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ללקוח של בוט ניהול האתר — הכול מגיע מהמספר של הבוט.
 *
 * זו החלטה של מוצר: לקוח שמנהל את האתר שלו בשיחה עם מספר אחד אינו אמור לקבל
 * הודעה על אותו מנוי ממספר אחר, שנראה לו כמו מספר זר. המספר הכללי נשאר לפניות
 * תמיכה שהלקוח פותח.
 *
 * והנקודה העדינה שהבדיקות כאן שומרות עליה: **מה ההודעה נושאת תלוי במי מחזיק
 * בטלפון.** המוצר מתיר להעביר את הסוכן לעובד או לסוכנות, וקישור תשלום חתום הוא
 * הזמנה להקליד את פרטי הכרטיס של העסק. שליחתו למספר כזה היא מסירת דף התשלום של
 * לקוח למי שבמקרה מחזיק במכשיר.
 */
class BotNumberRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'siteagent.enabled' => '1',
            'siteagent.phone_number_id' => '123456',
            'siteagent.token' => 'token',
            'siteagent.template_card_link' => 'card_link_template',
        ] as $key => $value) {
            Setting::put($key, $value);
        }

        SettingsServiceProvider::refreshFromDatabase();

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);
    }

    private function subscription(string $customerPhone, string $botPhone): Subscription
    {
        $customer = Customer::factory()->create(['phone' => $customerPhone]);
        $site = Site::factory()->create(['customer_id' => $customer->id]);

        SiteAgentSubscriber::create([
            'phone' => $botPhone,
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ]);

        return Subscription::factory()->create(['customer_id' => $customer->id]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sentBodies(): array
    {
        $bodies = [];

        foreach (Http::recorded() as [$request]) {
            $bodies[] = $request->data();
        }

        return $bodies;
    }

    /**
     * המספר של בעל העסק מקבל את דף התשלום.
     *
     * זה המקרה שבו הקישור החתום בטוח: מי שמחזיק בטלפון הוא מי שרשום כלקוח.
     */
    public function test_the_business_own_number_gets_the_signed_payment_page(): void
    {
        $subscription = $this->subscription('0501234567', '972501234567');

        $result = app(CardCaptureLinkSender::class)->send($subscription);

        $this->assertContains('וואטסאפ (מהמספר של הבוט)', $result['sent']);

        $body = $this->sentBodies()[0] ?? [];
        $this->assertSame('card_link_template', data_get($body, 'template.name'));
        $this->assertSame('972501234567', data_get($body, 'to'));

        $link = (string) data_get($body, 'template.components.0.parameters.1.text');
        $this->assertStringContainsString('/billing/update-card/', $link, 'בעל העסק אמור לקבל את דף התשלום עצמו.');
    }

    /**
     * ומספר שהסוכן נמסר אליו מקבל את האזור האישי, לא את דף התשלום.
     *
     * זו הבדיקה שמונעת את הנזק: עובד או סוכנות שמנהלים את האתר אינם אמורים לקבל
     * דף שבו מקלידים את פרטי הכרטיס של הלקוח שלהם. האזור האישי אינו מוסר דבר —
     * מי שפותח אותו עדיין חייב להתחבר עם הפרטים שברשומת הלקוח.
     */
    public function test_a_number_the_agent_was_handed_to_gets_the_sign_in_page(): void
    {
        $subscription = $this->subscription('0501234567', '972509999999');

        app(CardCaptureLinkSender::class)->send($subscription);

        $link = (string) data_get($this->sentBodies()[0] ?? [], 'template.components.0.parameters.1.text');

        $this->assertSame(route('portal.login'), $link);
        $this->assertStringNotContainsString('update-card', $link);
    }

    /**
     * ובלי תבנית מאושרת — המייל יוצא, הוואטסאפ מדולג בפירוש, והפער נרשם.
     *
     * המספר הכללי אינו גיבוי למוצר הזה, ולכן "אין תבנית" אינו יכול להפוך
     * להודעה שיוצאת ממספר אחר. אבל הוא גם אינו יכול להיעלם בשקט: תבנית חסרה
     * היא תקלת הגדרה שמישהו צריך לסגור.
     */
    public function test_without_a_template_the_message_is_skipped_and_recorded(): void
    {
        // ישירות ב-config ולא דרך ההגדרות: ברירות המחדל הפריסטיניות מזוכרות
        // פעם אחת לכל התהליך, כך שניקוי הגדרה מחזיר את הערך שהיה בזיכרון בזמן
        // שקובץ בדיקות אחר רץ — ומה שנבדק כאן תלוי אז בסדר ההרצה.
        $subscription = $this->subscription('0501234567', '972501234567');

        // אחרי יצירת הרשומות ולא לפניה: כתיבה למסד מרעננת את שכבת ההגדרות על
        // גבי ה-config, ולכן ערך שנקבע קודם נדרס בדרך.
        config(['siteagent.whatsapp.templates.card_link' => '']);

        $result = app(CardCaptureLinkSender::class)->send($subscription);

        $this->assertEmpty(array_filter(
            $result['sent'],
            fn (string $channel): bool => str_contains($channel, 'וואטסאפ'),
        ));

        $this->assertNotEmpty(array_filter(
            $result['skipped'],
            fn (string $reason): bool => str_contains($reason, 'אין תבנית מאושרת'),
        ));

        // ולא נטען שם שההודעה נשלחה במייל — רגל המייל עוד לא רצה.
        $this->assertEmpty(array_filter(
            $result['skipped'],
            fn (string $reason): bool => str_contains($reason, 'מייל'),
        ));

        $this->assertTrue(SystemLog::where('source', 'site-agent')
            ->where('message', 'like', '%חסרה תבנית%')->exists());

        // ושום דבר לא יצא מהמספר הכללי במקום.
        Http::assertNothingSent();
    }

    /**
     * המספר של בעל העסק מנצח גם כשהוא לא האחרון שאומת.
     *
     * ללקוח יכולים להיות כמה מספרים מחוברים בבת אחת — הבעלים ועוד עובד, או
     * מנהל לכל אתר — ומנהלים נוספים נרשמים בדרך כלל **אחרי** הבעלים. בחירת
     * האחרון הייתה שולחת את הודעת התשלום של הבעלים למנהל, עם קישור לאזור האישי
     * בלבד, בזמן שהמספר של הבעלים יושב שם ולא בשימוש.
     */
    public function test_the_owner_binding_wins_over_a_manager_added_later(): void
    {
        $subscription = $this->subscription('0501234567', '972501234567');
        $customer = $subscription->customer;

        // מנהל שנוסף אחר כך, ולכן הוא האחרון שאומת.
        $this->travel(1)->hour();
        SiteAgentSubscriber::create([
            'phone' => '972509999999',
            'customer_id' => $customer->id,
            'site_id' => Site::factory()->create(['customer_id' => $customer->id])->id,
            'verified_at' => now(),
        ]);

        app(CardCaptureLinkSender::class)->send($subscription);

        $body = $this->sentBodies()[0] ?? [];

        $this->assertSame('972501234567', data_get($body, 'to'), 'הודעת התשלום אמורה ללכת לבעלים.');
        $this->assertStringContainsString(
            '/billing/update-card/',
            (string) data_get($body, 'template.components.0.parameters.1.text'),
        );
    }

    /**
     * והודעה שכובתה בהגדרות אינה נשלחת גם ללקוחות הבוט.
     *
     * התבנית המאושרת קובעת את הנוסח, לא את ההחלטה אם לשלוח בכלל. מתג שמכבה
     * הודעה ובכל זאת היא מגיעה לחלק מהלקוחות הוא מתג שאי אפשר לסמוך עליו שוב.
     */
    public function test_a_notice_switched_off_in_settings_is_not_sent_over_the_bot(): void
    {
        NotificationTemplate::updateOrCreate(
            ['key' => 'card.capture', 'channel' => 'whatsapp'],
            ['body' => 'גוף כלשהו', 'enabled' => false],
        );

        $subscription = $this->subscription('0501234567', '972501234567');

        $result = app(CardCaptureLinkSender::class)->send($subscription);

        $this->assertContains('וואטסאפ (ההודעה כבויה בהגדרות)', $result['skipped']);
        Http::assertNothingSent();
    }

    /**
     * מנהל של אתר אחר אינו תחליף למנהל של האתר שההודעה עליו.
     *
     * ללקוח יכולים להיות אתרים אחדים, לכל אחד מנהל משלו. שליחת הודעת התשלום של
     * אתר א' למנהל של אתר ב' חושפת את החיוב של לקוח אחד בפני הסוכנות של אחר.
     * כשלאתר הנכון אין מנהל — אין למי לשלוח, וזו התשובה הנכונה.
     */
    public function test_a_manager_of_another_site_is_not_a_stand_in(): void
    {
        $customer = Customer::factory()->create(['phone' => '0501111111']);
        $siteA = Site::factory()->create(['customer_id' => $customer->id]);
        $siteB = Site::factory()->create(['customer_id' => $customer->id]);

        // מנהל רק לאתר ב', ואין מספר של הבעלים בכלל.
        SiteAgentSubscriber::create([
            'phone' => '972502222222',
            'customer_id' => $customer->id,
            'site_id' => $siteB->id,
            'verified_at' => now(),
        ]);

        $forSiteA = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'site_id' => $siteA->id,
        ]);

        $waha = \Mockery::mock(WahaClient::class);
        $waha->shouldNotReceive('sendMessage');
        $this->app->instance(WahaClient::class, $waha);

        $result = app(CardCaptureLinkSender::class)->send($forSiteA);

        // לא למנהל של אתר ב', ולא דרך המספר הכללי — ונאמר למה.
        Http::assertNothingSent();
        $this->assertNotEmpty(array_filter(
            $result['skipped'],
            fn (string $reason): bool => str_contains($reason, 'אין מספר מחובר לאתר'),
        ));
    }

    /**
     * והמתג הראשי של המוצר עוצר גם את ההודעות היוצאות.
     *
     * זה המתג שמושכים באירוע. מוצר שכובה וממשיך לכתוב ללקוחות מהמספר שלו הוא
     * מתג שלא עבד.
     */
    public function test_the_master_switch_stops_outgoing_notices_too(): void
    {
        $subscription = $this->subscription('0501234567', '972501234567');

        config(['siteagent.enabled' => false]);

        $result = app(CardCaptureLinkSender::class)->send($subscription);

        $this->assertNotEmpty(array_filter(
            $result['failed'],
            fn (string $reason): bool => str_contains($reason, 'אינו מוגדר'),
        ));

        Http::assertNothingSent();
    }

    /**
     * מספר שנלמד מפנייה לתמיכה אינו הוכחה שזה בעל העסק.
     *
     * `whatsapp_jid` נחתם על רשומת הלקוח ממי שכתב לתמיכה ראשון והותאם ללקוח
     * הזה — כלומר עובד או סוכנות שפנו לפני הבעלים הופכים למספר הרשום. קבלתו
     * כהוכחת בעלות הייתה מסווגת בדיוק את המספר שנמסר לאחר כ"המספר של העסק",
     * ושולחת אליו את דף התשלום החתום. זו אותה דליפה שהמסלול קיים כדי למנוע,
     * רק מהדלת האחורית.
     */
    public function test_a_jid_learned_from_support_is_not_proof_of_ownership(): void
    {
        $subscription = $this->subscription('0501234567', '972509999999');

        // כפי ש-IngestWhatsappMessageJob עושה: המספר של העובד נחתם על הלקוח.
        $subscription->customer->update(['whatsapp_jid' => '972509999999@c.us']);

        app(CardCaptureLinkSender::class)->send($subscription->fresh());

        $link = (string) data_get($this->sentBodies()[0] ?? [], 'template.components.0.parameters.1.text');

        $this->assertSame(route('portal.login'), $link, 'מספר שנלמד מפנייה לתמיכה אינו מזכה בדף התשלום.');
        $this->assertStringNotContainsString('update-card', $link);
    }

    /**
     * ומספר בוט שאינו מוגדר כרגע הוא כישלון, לא סיבה ללכת למספר הכללי.
     *
     * החלפת טוקן היא פעולה שגרתית של כמה דקות. בלי ההבחנה הזאת היא הייתה
     * שולחת את דף התשלום החתום של הלקוח ממספר שהוא אינו מזהה — בדיוק מה שהמסלול
     * הזה קיים כדי למנוע.
     */
    public function test_an_unconfigured_bot_number_fails_rather_than_falling_back(): void
    {
        $waha = \Mockery::mock(WahaClient::class);
        $waha->shouldNotReceive('sendMessage');
        $this->app->instance(WahaClient::class, $waha);

        $subscription = $this->subscription('0501234567', '972501234567');

        config(['siteagent.whatsapp.token' => '']);

        $result = app(CardCaptureLinkSender::class)->send($subscription);

        $this->assertNotEmpty(array_filter(
            $result['failed'],
            fn (string $reason): bool => str_contains($reason, 'אינו מוגדר'),
        ));

        Http::assertNothingSent();
    }

    /**
     * ולקוח שאינו של הבוט ממשיך לקבל מהמספר הכללי, כמו תמיד.
     *
     * נבדק עם ציפייה מפורשת ולא בשלילה בלבד: "לא נשלחה קריאה למטא" מתקיים גם
     * כששום דבר לא נשלח בכלל, וזו בדיוק הבדיקה שעוברת בלי להוכיח דבר.
     */
    public function test_a_customer_without_the_bot_still_uses_the_support_number(): void
    {
        $waha = \Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->once();
        $this->app->instance(WahaClient::class, $waha);

        $customer = Customer::factory()->create([
            'phone' => '0501234567',
            'email' => 'c@example.co.il',
            'whatsapp_jid' => null,
        ]);
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

        app(CardCaptureLinkSender::class)->send($subscription);

        // ושום דבר לא יצא דרך המספר של הבוט.
        Http::assertNothingSent();
    }
}
