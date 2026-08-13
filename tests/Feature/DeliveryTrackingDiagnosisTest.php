<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Models\Broadcast;
use App\Models\NotificationLog;
use App\Models\WebhookEvent;
use App\Services\Mail\PostmarkTracking;
use App\Services\Support\DeliveryTrackingDiagnosis;
use App\Support\WebhookRejections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tests\TestCase;

/**
 * "אין מדדי פתיחה" — איפה בדיוק זה נעצר.
 *
 * אותו מסך ריק מתאים לחמש תקלות שונות, ולכל אחת מהן תיקון אחר: המייל לא נשלח
 * דרך Postmark; Postmark פונה אלינו ונדחה; Postmark מעולם לא פנה; הוא פונה אבל
 * לא שולח פתיחות; או שפתיחות מגיעות ואינן משויכות לשום הודעה שלנו.
 *
 * הכפתור "Check" של Postmark אינו מבחין ביניהן — הוא מסתפק בתשובת 200, ואנחנו
 * מחזירים 200 גם לאירוע שלא התאים לדבר (סירוב היה גורם לספק לנסות שוב אירוע
 * שאין מה לעשות איתו). לכן האבחון נשען על העקבות שכבר נשמרות: כל פנייה שהתקבלה
 * היא שורה ב-webhook_events.
 */
class DeliveryTrackingDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['mail.default' => 'postmark', 'services.postmark.token' => 'server-token']);
    }

    private function diagnose(): array
    {
        return app(DeliveryTrackingDiagnosis::class)->run();
    }

    private function broadcast(): Broadcast
    {
        return Broadcast::create(['subject' => 'עדכון', 'body' => 'שלום', 'channel' => 'email', 'status' => 'sent']);
    }

    private function event(string $type, array $payload = []): WebhookEvent
    {
        [$event] = WebhookEvent::record(
            WebhookSource::Email,
            'delivery_'.strtolower($type),
            $type.':'.uniqid(),
            ['RecordType' => $type] + $payload,
        );

        return $event;
    }

    /** בלי Postmark בכלל — אין ממי לצפות לדיווחים. */
    public function test_it_says_when_mail_does_not_go_through_the_provider(): void
    {
        config(['services.postmark.token' => null]);

        $this->assertStringContainsString('אינה שולחת מייל דרך Postmark', $this->diagnose()['verdict']);
    }

    /** פניות נדחות — הסוד בכתובת שגוי. */
    public function test_it_names_a_rejected_call_rather_than_a_missing_one(): void
    {
        WebhookRejections::record('email.delivery');

        $diagnosis = $this->diagnose();

        $this->assertStringContainsString('נדחית', $diagnosis['verdict']);
        $this->assertStringContainsString('secret', $diagnosis['fix']);
    }

    /** שום אירוע לא הגיע — הוובהוק אינו מוגדר, או מוגדר במקום אחר. */
    public function test_it_says_when_the_provider_never_called(): void
    {
        $diagnosis = $this->diagnose();

        $this->assertSame(0, $diagnosis['total']);
        $this->assertStringContainsString('מעולם לא פנה', $diagnosis['verdict']);
        $this->assertNull($diagnosis['lastEventAt']);
    }

    /**
     * התקלה שקשה ביותר לראות: אירועים מגיעים, ואין ביניהם פתיחות.
     *
     * מי שהגדיר הכל ובדק שהוובהוק מחזיר 200 מסיק שהמדידה עובדת. היא לא: Open
     * הוא אירוע נפרד שצריך לסמן, ומדידת פתיחות היא הגדרה נפרדת על הזרם.
     */
    public function test_events_arriving_without_a_single_open_is_named_as_the_fault(): void
    {
        $this->event('Delivery');
        $this->event('Bounce');

        $diagnosis = $this->diagnose();

        $this->assertSame(2, $diagnosis['total']);
        $this->assertStringContainsString('אין ביניהם אף פתיחה', $diagnosis['verdict']);
        $this->assertStringContainsString('Open Tracking', $diagnosis['fix']);
        $this->assertNotNull($diagnosis['lastEventAt']);
    }

    /** פתיחות מגיעות ואינן משויכות — תקלה אצלנו, ונאמר כך. */
    public function test_opens_that_match_nothing_are_named_as_our_fault(): void
    {
        $this->event('Open', ['MessageID' => 'postmark-uuid-1']);
        $this->event('Open', ['MessageID' => 'postmark-uuid-2']);

        NotificationLog::factory()->create([
            'channel' => 'email',
            'broadcast_id' => $this->broadcast()->id,
            'provider_message_id' => null,
        ]);

        $diagnosis = $this->diagnose();

        $this->assertSame(2, $diagnosis['opens']);
        $this->assertSame(0, $diagnosis['openMatched']);
        $this->assertStringContainsString('אינה משויכת', $diagnosis['verdict']);
        // וכמה מההודעות בכלל ניתנות לשיוך — זה מה שהופך את זה לניתן לתיקון.
        $this->assertSame(1, $diagnosis['sent']);
        $this->assertSame(0, $diagnosis['tracked']);
    }

    /** וכששויכו — נאמר שהמדידה עובדת, ולא מוצג אבחון של תקלה שאין. */
    public function test_matched_opens_report_a_working_chain(): void
    {
        $this->event('Open', ['MessageID' => 'postmark-uuid-1']);

        NotificationLog::factory()->create([
            'channel' => 'email',
            'broadcast_id' => $this->broadcast()->id,
            'provider_message_id' => 'postmark-uuid-1',
        ]);

        $diagnosis = $this->diagnose();

        $this->assertSame(1, $diagnosis['openMatched']);
        $this->assertStringContainsString('המדידה עובדת', $diagnosis['verdict']);
    }

    /** האירועים נספרים לפי סוג, בשמות ש-Postmark משתמש בהם. */
    public function test_events_are_counted_by_the_names_postmark_uses(): void
    {
        $this->event('Delivery');
        $this->event('Delivery');
        $this->event('SpamComplaint');

        $events = $this->diagnose()['events'];

        $this->assertSame(2, $events['Delivery']);
        $this->assertSame(1, $events['SpamComplaint']);
    }

    /*
    | ----------------------------------------------------------------
    | הבקשה למדוד פתיחות — שתגיע באמת
    | ----------------------------------------------------------------
    */

    /**
     * X-PM-TrackOpens היא הוראת SMTP. דרך ה-API של Postmark השדה הוא TrackOpens
     * בגוף הבקשה, והכותרת נכתבת להודעה כטקסט רגיל — כלומר הבקשה למדוד פתיחות
     * לא מגיעה לשום מקום, והמדידה תלויה כולה בהגדרה בחשבון שאיש אינו רואה מהפאנל.
     */
    public function test_a_message_asking_for_open_tracking_gets_it_in_the_request_body(): void
    {
        $sent = $this->captureSend([
            'Headers' => [['Name' => 'X-PM-TrackOpens', 'Value' => 'true']],
        ]);

        $this->assertTrue($sent['json']['TrackOpens']);
    }

    /** ומייל שלא ביקש — לא נמדד. חשבוניות ותשובות לפניות אינן דיוור. */
    public function test_a_message_that_did_not_ask_is_left_alone(): void
    {
        $sent = $this->captureSend(['Headers' => [['Name' => 'X-Multioto-Log', 'Value' => '7']]]);

        $this->assertArrayNotHasKey('TrackOpens', $sent['json']);
    }

    /** בקשות אחרות ל-Postmark (לא שליחה) אינן נוגעות. */
    public function test_other_calls_are_untouched(): void
    {
        $client = new PostmarkTracking($spy = new class implements HttpClientInterface
        {
            public array $options = [];

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->options = $options;

                return new MockResponse('{}');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \RuntimeException('not used');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        });

        $client->request('GET', 'https://api.postmarkapp.com/senders', ['json' => ['a' => 1]]);

        $this->assertArrayNotHasKey('TrackOpens', $spy->options['json']);
    }

    /**
     * Push a payload through the decorator and return what the inner client got.
     *
     * @return array<string, mixed>
     */
    private function captureSend(array $payload): array
    {
        $spy = new class implements HttpClientInterface
        {
            public array $options = [];

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->options = $options;

                return new MockResponse('{}');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \RuntimeException('not used');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };

        (new PostmarkTracking($spy))->request('POST', 'https://api.postmarkapp.com/email', ['json' => $payload]);

        return $spy->options;
    }
}
