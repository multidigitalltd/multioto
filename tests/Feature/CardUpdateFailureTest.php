<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * כשלקוח לא מצליח למסור לנו כרטיס.
 *
 * "העדכון לא הושלם" הוא כל מה שהלקוח רואה, והוא אותו עמוד בדיוק לכל סיבה
 * אפשרית. הסיבה האמיתית קיימת — קארדקום שולחת אותה — אבל היא לא הייתה בשום
 * מקום שאדם יכול לקרוא, ואיש בצוות לא היה מקבל הודעה. לקוח שניסה לשלם ולא
 * הצליח נכנס אחרי כמה ימים לדאנינג בלי שאיש ידע שהוא ניסה.
 */
class CardUpdateFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.cardcom.terminal_number' => '1000',
            'billing.cardcom.api_name' => 'test',
            'billing.notifications.team_email' => 'team@multidigital.co.il',
        ]);
    }

    /**
     * אימות כרטיס יוצא כ-J5 על סכום אפס — וזה הנכון.
     *
     * קארדקום מציבה מינימום משלה ל-J5 על אפס: קליטות אמיתיות חוזרות עם
     * Amount 0.01 ו-ResponseCode 701 ("עסקת אישור תקינה — תפיסת מסגרת אשראי").
     * כלומר אפס מייצר את התפיסה הקטנה ביותר האפשרית על הכרטיס, והעלאתו רק
     * מגדילה את הסכום שנתפס אצל הלקוח.
     */
    public function test_a_card_is_validated_with_a_j5_on_the_smallest_possible_hold(): void
    {
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x'])]);

        $customer = Customer::factory()->create();

        app(CardcomClient::class)->createTokenLowProfile($customer->id, 'https://x/ok', 'https://x/no', 'https://x/hook');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['Operation'] ?? null) === 'CreateTokenOnly'
                && ($body['AdvancedDefinition']['JValidateType'] ?? null) === 5
                && (float) ($body['Amount'] ?? -1) === 0.0;
        });
    }

    /** ומסוף שדורש סכום מפורש יכול לקבל אחד. */
    public function test_the_validation_amount_is_configurable(): void
    {
        config(['billing.cardcom.token_validation_amount' => 1]);
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x'])]);

        app(CardcomClient::class)->createTokenLowProfile(
            Customer::factory()->create()->id, 'https://x/ok', 'https://x/no', 'https://x/hook',
        );

        Http::assertSent(fn ($request): bool => (float) ($request->data()['Amount'] ?? -1) === 1.0);
    }

    /**
     * וכישלון בקליטת כרטיס מגיע לצוות עם הסיבה של קארדקום.
     *
     * קוד לבדו ("2", "60000042") אינו אומר דבר למי שקורא אותו; המילים הן החלק
     * שמבדיל בין "להתקשר ללקוח" לבין "לשנות הגדרה במסוף".
     */
    public function test_a_failed_capture_reaches_the_team_with_the_reason(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['ResponseCode' => 2, 'Description' => 'כרטיס נדחה על ידי חברת האשראי'])]);

        $customer = Customer::factory()->create(['name' => 'עסק לדוגמה']);

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile',
            'lp-1',
            ['LowProfileId' => 'lp-1', 'ReturnValue' => (string) $customer->id],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->subjectLine, 'עדכון כרטיס נכשל')
            && str_contains($mail->subjectLine, 'עסק לדוגמה'));
    }

    /**
     * וקליטה שהצליחה אינה מייצרת התראה.
     *
     * התראה על כל כרטיס שנשמר הייתה הופכת את ההתראה על כישלון לרעש שאיש לא
     * קורא — וזו ההתראה היחידה שחייבים לקרוא.
     */
    public function test_a_successful_capture_is_not_announced_as_a_failure(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response([
            'ResponseCode' => 0,
            'ReturnValue' => '1',
            'TokenInfo' => ['Token' => 'tok-1', 'CardLast4Digits' => '4242', 'CardMonth' => 12, 'CardYear' => 30],
        ])]);

        $customer = Customer::factory()->create();

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile',
            'lp-2',
            ['LowProfileId' => 'lp-2', 'ReturnValue' => (string) $customer->id],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame(1, $customer->paymentTokens()->count());
    }
}
