<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Jobs\CheckWhatsappInboundJob;
use App\Mail\NotificationMail;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * התראה כשקליטת הפניות מוואטסאפ נשברת.
 *
 * לנתיב נכנס שבור אין סימפטום: השליחה החוצה ממשיכה לעבוד, שום שגיאה לא נזרקת,
 * ותור הפניות פשוט מפסיק להתמלא — בדיוק כמו יום שקט. זה למה זה יכול להימשך
 * שבועות לפני שמישהו מבחין שלקוחות כותבים לתוך שתיקה.
 */
class WhatsappInboundAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.waha.base_url' => 'http://waha.test',
            'billing.waha.api_key' => 'key',
            'billing.waha.session' => 'default',
            'billing.waha.owner_number' => '',
            'billing.notifications.team_email' => 'team@example.com',
        ]);
    }

    private function sessionReturns(array $webhooks): void
    {
        Http::fake(['*/api/sessions/default' => Http::response(['config' => ['webhooks' => $webhooks]])]);
    }

    /**
     * A healthy inbound path end to end: the event arrived, was processed, and
     * a ticket message came out of it. The event alone is no longer "healthy" —
     * messages that arrive and open nothing are exactly the fault being watched.
     */
    private function message(): void
    {
        WebhookEvent::create([
            'source' => WebhookSource::Waha, 'event_type' => 'message',
            'external_id' => 'evt-'.uniqid(),
            'payload' => ['event' => 'message', 'payload' => ['from' => '972500000000@c.us']],
            'processed_at' => now(),
        ]);

        Ticket::create([
            'channel' => TicketChannel::Whatsapp, 'subject' => 'פנייה', 'status' => TicketStatus::Open,
        ])->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Whatsapp,
            'body' => 'שלום',
            'author' => MessageAuthor::Customer,
        ]);
    }

    private function check(): void
    {
        $this->app->call([app(CheckWhatsappInboundJob::class), 'handle']);
    }

    /** רישום חסר — התראה יוצאת, ואומרת שהשליחה החוצה דווקא עובדת. */
    public function test_it_alerts_when_the_inbound_path_is_broken(): void
    {
        Mail::fake();
        $this->sessionReturns([]);

        $this->check();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->subjectLine, 'לא נקלטות')
            && str_contains($mail->bodyText, 'שליחה החוצה'));
    }

    /** אותה תקלה בשעה הבאה אינה מתריעה שוב — התראה חוזרת היא התראה שמסננים. */
    public function test_a_standing_fault_is_not_re_alerted(): void
    {
        Mail::fake();
        $this->sessionReturns([]);

        $this->check();
        $this->check();

        Mail::assertSentCount(1);
    }

    /** תיקון מדווח פעם אחת — ככה יודעים שהתיקון תפס. */
    public function test_it_reports_recovery_once(): void
    {
        Mail::fake();
        // רצף תשובות: קודם בלי רישום, ואחר כך עם רישום תקין.
        Http::fake(['*/api/sessions/default' => Http::sequence()
            ->push(['config' => ['webhooks' => []]])
            ->push(['config' => ['webhooks' => [['url' => route('webhooks.waha')]]]])
            ->push(['config' => ['webhooks' => [['url' => route('webhooks.waha')]]]])]);

        $this->check();

        $this->message();
        $this->check();
        $this->check();

        Mail::assertSentCount(2);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->subjectLine, 'חזרה לעבוד'));
    }

    /**
     * שבוע שקט עם רישום תקין אינו תקלה. התראה שנדלקת בשבוע איטי היא התראה
     * שלומדים להתעלם ממנה — ואז מתעלמים גם מהאמיתית.
     */
    public function test_a_quiet_week_is_not_an_alert(): void
    {
        Mail::fake();
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        // created_at is not fillable — age it explicitly, or the "old" message
        // lands as today's and the test passes for the wrong reason.
        WebhookEvent::create([
            'source' => WebhookSource::Waha, 'event_type' => 'message',
            'external_id' => 'old', 'payload' => [],
        ])->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->check();

        Mail::assertNothingSent();
    }

    /** התקנה בלי וואטסאפ מוגדר לא מקבלת התראות על וואטסאפ. */
    public function test_an_install_without_whatsapp_is_left_alone(): void
    {
        Mail::fake();
        config(['billing.waha.api_key' => null]);

        $this->check();

        Mail::assertNothingSent();
        $this->assertArrayNotHasKey('waha.inbound_alert_state', Setting::map());
    }

    /** מצב תקין נשמר, כך שתקלה עתידית תזוהה כשינוי ותתריע. */
    public function test_a_healthy_state_is_remembered(): void
    {
        Mail::fake();
        $this->sessionReturns([['url' => route('webhooks.waha')]]);
        $this->message();

        $this->check();

        $this->assertSame('ok', Setting::map()['waha.inbound_alert_state'] ?? null);
    }
}
