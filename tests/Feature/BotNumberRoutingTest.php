<?php

namespace Tests\Feature;

use App\Models\Customer;
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
        Setting::put('siteagent.template_card_link', '');
        SettingsServiceProvider::refreshFromDatabase();

        $subscription = $this->subscription('0501234567', '972501234567');

        $result = app(CardCaptureLinkSender::class)->send($subscription);

        $this->assertSame([], $result['sent'] === [] ? [] : array_filter(
            $result['sent'],
            fn (string $channel): bool => str_contains($channel, 'וואטסאפ'),
        ));

        $this->assertNotEmpty(array_filter(
            $result['skipped'],
            fn (string $reason): bool => str_contains($reason, 'אין תבנית מאושרת'),
        ));

        $this->assertTrue(SystemLog::where('source', 'site-agent')
            ->where('message', 'like', '%חסרה תבנית%')->exists());

        // ושום דבר לא יצא מהמספר הכללי במקום.
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
