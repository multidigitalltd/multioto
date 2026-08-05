<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Models\WebhookEvent;
use App\Services\Waha\InboundDiagnosis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * למה לא מגיעות פניות מוואטסאפ.
 *
 * ששליחה החוצה עובדת מוכיח שהסשן מחובר — ולא מוכיח דבר על הכיוון הנכנס, שתלוי
 * ברישום webhook בסשן של WAHA שמצביע חזרה אלינו. כשהוא חסר, המסך פשוט שותק:
 * בלי שגיאה, בלי פנייה, בלי רמז. השתיקה היא התקלה.
 */
class WahaInboundDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.waha.base_url' => 'http://waha.test', 'billing.waha.session' => 'default']);
    }

    private function sessionReturns(array $webhooks): void
    {
        Http::fake(['*/api/sessions/default' => Http::response(['config' => ['webhooks' => $webhooks]])]);
    }

    /**
     * created_at is not fillable on WebhookEvent, so an "old" event has to be
     * aged explicitly — passing it to create() silently stamps it now, and the
     * test then passes for the wrong reason.
     */
    private function event(string $type, ?string $at = null): void
    {
        $event = WebhookEvent::create([
            'source' => WebhookSource::Waha,
            'event_type' => $type,
            'external_id' => 'evt-'.uniqid(),
            'payload' => [],
        ]);

        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }
    }

    /** אין רישום בכלל — זה הרוב המכריע של המקרים, ויש כפתור שמסדר אותו. */
    public function test_it_reports_when_nothing_is_registered(): void
    {
        $this->sessionReturns([]);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('לא מדווח למערכת', $result['title']);
        $this->assertStringContainsString('הפעלת האזנה', $result['detail']);
    }

    /** רשום — אבל ליעד אחר. הכתובת מוצגת כדי שיהיה ברור לאן זה הלך. */
    public function test_it_reports_a_webhook_pointing_elsewhere(): void
    {
        $this->sessionReturns([['url' => 'https://n8n.example.com/hook/waha', 'events' => ['message']]]);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('n8n.example.com', $result['detail']);
    }

    /** הסוד לעולם לא מוחזר למסך, גם כשהכתובת מוצגת. */
    public function test_it_never_echoes_the_secret_back(): void
    {
        $this->sessionReturns([['url' => 'https://other.example.com/webhooks/waha?secret=SUPERSECRET']]);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertStringNotContainsString('SUPERSECRET', $result['detail']);
    }

    /**
     * רשום נכון אבל שום דבר לא הגיע: WAHA לא מצליח להגיע לכתובת מהמקום שבו
     * הוא רץ — כתובת שנכונה בדפדפן ואינה נכונה מתוך קונטיינר אחר.
     */
    public function test_it_reports_a_correct_registration_that_never_delivered(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha').'?secret=x', 'events' => ['message']]]);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('APP_URL', $result['detail']);
    }

    /** מגיעים אירועים אחרים אבל לא הודעות — סוג האירוע הרשום אינו הנכון. */
    public function test_it_reports_events_arriving_without_messages(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('session.status');

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('session.status', $result['detail']);
    }

    /** הכול עובד — ונאמר במפורש, עם כמה הודעות התקבלו. */
    public function test_it_confirms_a_healthy_inbound_path(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha').'?secret=x', 'events' => ['message']]]);
        $this->event('message');
        $this->event('message');

        $result = app(InboundDiagnosis::class)->run();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('2 הודעות', $result['detail']);
    }

    /** שרת WAHA שלא נענה — זו תשובה בפני עצמה, לא כישלון שקט. */
    public function test_it_reports_an_unreachable_waha_server(): void
    {
        Http::fake(['*/api/sessions/default' => Http::response('nope', 500)]);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('WAHA', $result['title']);
    }

    /** שבוע שקט עם רישום תקין אינו מדווח כתקלה — יש לו מצב משלו. */
    public function test_a_quiet_week_is_reported_as_quiet_not_broken(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message', now()->subDays(30)->toDateTimeString());

        $result = app(InboundDiagnosis::class)->run();

        $this->assertSame('quiet', $result['state']);
        $this->assertNotContains('quiet', InboundDiagnosis::FAULTS);
    }

    /** לכל מצב יש מזהה יציב, כדי שההתראה תחליט לפיו ולא לפי ניסוח בעברית. */
    public function test_every_outcome_carries_a_stable_state(): void
    {
        $this->sessionReturns([]);

        $this->assertSame('not_registered', app(InboundDiagnosis::class)->run()['state']);
    }
}
