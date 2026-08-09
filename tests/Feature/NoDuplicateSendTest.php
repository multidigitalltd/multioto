<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotificationJob;
use App\Jobs\SendTicketReplyJob;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * הודעה שיצאה ללקוח לא תישלח פעם שנייה.
 *
 * העבודות האלה רצות בתור עם שלושה ניסיונות, וכל אחת מהן סימנה "נשלח" רק אחרי
 * שסיימה את כל השאר. כל שורה שבין השליחה לסימון — לולאת קבצים מצורפים, מזהה
 * שהספק החזיר בצורה אחרת ממה שציפינו — הייתה הזדמנות להיכשל אחרי שההודעה כבר
 * הגיעה ללקוח, ולקבל בתשובה ניסיון חוזר ששולח אותה שוב. מהצד של הלקוח זו אותה
 * הודעה פעמיים או שלוש, ומערכת שנראית שבורה.
 *
 * הבדיקות כאן הן על הסדר: מסמנים לפני שאפשר להיכשל, ולא אחרי.
 */
class NoDuplicateSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
        ]);
    }

    private function whatsappTicket(): Ticket
    {
        return Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'x',
            'status' => TicketStatus::Open,
            'external_thread_ref' => '972501234567@c.us',
        ]);
    }

    private function outboundMessage(Ticket $ticket)
    {
        return $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp,
            'body' => 'התשובה שלנו',
            'author' => MessageAuthor::Agent,
        ]);
    }

    /**
     * מנוע WAHA שמחזיר את המזהה כאובייקט (WEBJS) — ולא כמחרוזת.
     *
     * זו הצורה שהפילה את הכתיבה אחרי השליחה: קריאה של המזהה כמחרוזת עבדה רק על
     * חלק מהמנועים, ובשאר ההודעה כבר יצאה ללקוח כשהעבודה נפלה.
     */
    public function test_a_reply_is_sent_once_even_when_the_engine_returns_an_object_id(): void
    {
        Http::fake(['*/api/sendText' => Http::response([
            'id' => ['fromMe' => true, 'id' => '3EB0', '_serialized' => 'true_972501234567@c.us_3EB0'],
        ])]);

        $message = $this->outboundMessage($this->whatsappTicket());

        SendTicketReplyJob::dispatchSync($message->id);

        $this->assertSame('true_972501234567@c.us_3EB0', $message->refresh()->external_message_id);

        // ניסיון חוזר של אותה עבודה עוצר בשער ואינו שולח שוב.
        SendTicketReplyJob::dispatchSync($message->id);

        Http::assertSentCount(1);
    }

    /**
     * מנוע שאינו מחזיר מזהה כלל (NOWEB מחזיר `key`) — ההודעה עדיין מסומנת.
     *
     * בלי סימן משלנו, הודעה שנשלחה בהצלחה נשארה בלי "נשלח", וכל ניסיון חוזר
     * שלח אותה מחדש.
     */
    public function test_a_reply_is_marked_sent_even_when_the_engine_returns_no_id(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['key' => ['id' => 'ABC']])]);

        $message = $this->outboundMessage($this->whatsappTicket());

        SendTicketReplyJob::dispatchSync($message->id);

        $this->assertNotNull($message->refresh()->external_message_id);

        SendTicketReplyJob::dispatchSync($message->id);

        Http::assertSentCount(1);
    }

    /** אישור קבלה נשלח פעם אחת, גם כשהעבודה רצה שוב. */
    public function test_an_acknowledgement_is_sent_once_across_retries(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'a1'])]);

        $ticket = $this->whatsappTicket();

        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.received');
        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.received');
        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.received');

        Http::assertSentCount(1);
    }

    /**
     * כתיבה שנכשלה מסיבה שאינה כפילות אינה נחשבת ל"כבר נשלח".
     *
     * ניתוק רגעי מבסיס הנתונים אינו ראיה לכך שהלקוח קיבל משהו. אם הוא ייבלע
     * כאילו היה כפילות, ההודעה נעלמת בשקט והתור מקבל הודעה שאין מה לנסות שוב.
     */
    public function test_a_write_failure_that_is_not_a_duplicate_is_not_swallowed(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'a1'])]);

        $ticket = $this->whatsappTicket();

        // כל כתיבה של הודעה נכשלת — לא בגלל מפתח כפול.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "ticket_messages"')) {
                throw new \RuntimeException('connection lost');
            }
        });

        $this->expectException(\Throwable::class);

        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.received');
    }

    /**
     * שליחה שנכשלה באמת — אין סימון, והניסיון הבא שולח כרגיל.
     *
     * הסימון מראש נועד למנוע כפילות, לא להפוך תקלת רשת אחת להודעה שלעולם לא
     * תגיע: מה שלא יצא, ייצא בניסיון הבא.
     */
    public function test_a_failed_acknowledgement_leaves_nothing_behind_and_is_retried(): void
    {
        // רצף ולא שתי הגדרות: קריאה שנייה ל-Http::fake רק מוסיפה לסוף הרשימה,
        // וההתאמה הראשונה היא שקובעת — כלומר הכישלון היה נשאר לנצח.
        Http::fake(['*/api/sendText' => Http::sequence()
            ->push([], 500)
            ->push(['id' => 'a1']),
        ]);

        $ticket = $this->whatsappTicket();

        try {
            SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.received');
        } catch (\Throwable) {
            // הכישלון מגיע לתור כדי שינסה שוב — כאן רק לא נותנים לו להפיל את הבדיקה.
        }

        $this->assertSame(0, $ticket->messages()->count());

        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.received');

        $this->assertSame(1, $ticket->messages()->count());
    }
}
