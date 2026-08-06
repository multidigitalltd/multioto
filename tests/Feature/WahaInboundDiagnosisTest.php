<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Models\Ticket;
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
    private function event(string $type, ?string $at = null, ?string $from = '972500000000@c.us', bool $processed = true): void
    {
        $event = WebhookEvent::create([
            'source' => WebhookSource::Waha,
            'event_type' => $type,
            'external_id' => 'evt-'.uniqid(),
            'payload' => ['event' => $type, 'payload' => ['from' => $from]],
            'processed_at' => $processed ? now() : null,
        ]);

        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }
    }

    /** הודעה שנכנסה בפועל לפנייה — ההוכחה שהמסלול עבד עד הסוף. */
    private function ticketMessage(): void
    {
        $ticket = Ticket::create([
            'channel' => TicketChannel::Whatsapp, 'subject' => 'פנייה', 'status' => TicketStatus::Open,
        ]);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Whatsapp,
            'body' => 'שלום',
            'author' => MessageAuthor::Customer,
        ]);
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
        $this->event('session.status', from: null);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('session.status', $result['detail']);
    }

    /** הכול עובד — ונאמר במפורש, עם כמה הודעות התקבלו וכמה נכנסו לפניות. */
    public function test_it_confirms_a_healthy_inbound_path(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha').'?secret=x', 'events' => ['message']]]);
        $this->event('message');
        $this->event('message');
        $this->ticketMessage();

        $result = app(InboundDiagnosis::class)->run();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('2 הודעות', $result['detail']);
    }

    /**
     * הודעות מגיעות אבל אף פנייה לא נפתחת — בדיקה שנעצרת ב"נמסר" הייתה מדווחת
     * תקין בזמן שהתור ריק, וזו התשובה המטעה מכולן.
     */
    public function test_messages_arriving_without_tickets_is_a_fault(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message');

        $result = app(InboundDiagnosis::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertSame('no_tickets', $result['state']);
    }

    /** הודעות שנרשמו ולא עובדו: תור העבודות אינו רץ. */
    public function test_unprocessed_messages_point_at_a_stopped_queue(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message', now()->subHour()->toDateTimeString(), processed: false);

        $result = app(InboundDiagnosis::class)->run();

        $this->assertSame('not_processed', $result['state']);
        $this->assertStringContainsString('Horizon', $result['detail']);
    }

    /** הודעה מלפני רגע שטרם עובדה אינה תקלה — היא עדיין בדרך. */
    public function test_a_just_arrived_message_is_not_called_stuck(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message', processed: false);
        $this->ticketMessage();

        $this->assertSame('ok', app(InboundDiagnosis::class)->run()['state']);
    }

    /**
     * הבדיקה ששלחה את השאלה הזאת: כל ההודעות הגיעו ממספר האישורים, שהוא צ׳אט
     * התפעול של הצוות ולעולם אינו פותח פניות לקוח.
     */
    public function test_messages_only_from_the_management_chat_are_explained(): void
    {
        config(['billing.waha.owner_number' => '0501111111']);
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message', from: '972501111111@c.us');

        $result = app(InboundDiagnosis::class)->run();

        $this->assertSame('owner_only', $result['state']);
        $this->assertStringContainsString('ממספר אחר', $result['detail']);
    }

    /** וכך גם הודעות שהגיעו רק מקבוצות — המערכת מקשיבה לצ׳אטים ישירים. */
    public function test_messages_only_from_groups_are_explained(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message', from: '12036@g.us');

        $result = app(InboundDiagnosis::class)->run();

        $this->assertSame('groups_only', $result['state']);
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

    /**
     * ערוץ שקיבל הודעות בקביעות ואז השתתק לא "נהיה שקט" — הוא נפסק. לקרוא לזה
     * שבוע איטי זה מה שאיפשר להפסקה אמיתית לשבת שמונה ימים בלי שאיש ידע.
     */
    public function test_a_channel_that_stopped_after_delivering_regularly_is_a_fault(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);

        foreach ([40, 30, 20, 9] as $daysAgo) {
            $this->event('message', now()->subDays($daysAgo)->toDateTimeString());
        }

        $result = app(InboundDiagnosis::class)->run();

        $this->assertSame('stalled', $result['state']);
        $this->assertContains('stalled', InboundDiagnosis::FAULTS);
    }

    /** ערוץ שמעולם לא היה לו קצב אינו "נפסק" — שם שקט הוא באמת שקט. */
    public function test_a_channel_without_a_rhythm_stays_merely_quiet(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message', now()->subDays(30)->toDateTimeString());

        $this->assertSame('quiet', app(InboundDiagnosis::class)->run()['state']);
    }

    /** הפקודה מהשורה מחזירה כישלון על תקלה — כדי שאפשר יהיה לתלות בה בדיקה. */
    public function test_the_console_command_fails_on_a_fault(): void
    {
        $this->sessionReturns([]);

        $this->artisan('waha:inbound')->assertFailed();
    }

    /** ועל מצב תקין — הצלחה. */
    public function test_the_console_command_succeeds_when_healthy(): void
    {
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->event('message');
        $this->ticketMessage();

        $this->artisan('waha:inbound')->assertSuccessful();
    }
}
