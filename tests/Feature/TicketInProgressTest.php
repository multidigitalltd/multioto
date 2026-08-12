<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "בטיפול" — פנייה שנלקחה לעבודה.
 *
 * עד עכשיו היו שתי אפשרויות בלבד: פנייה פתוחה (כלומר ממתינה לנו) או פנייה
 * שנסגרה. פנייה שהצוות עובד עליה שלושה ימים נשארה כל אותו זמן במונה
 * "ממתינות למענה", ובמקביל הלקוח לא ידע דבר — שני צדדים של אותה בעיה: מצב
 * שקיים במציאות ולא היה קיים במערכת.
 */
class TicketInProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
            // הודעה קבועה, בלי המודל — הבדיקה כאן היא על המנגנון.
            'billing.ai.dynamic_ack' => false,
        ]);
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'האתר איטי',
            'status' => TicketStatus::Open,
            'external_thread_ref' => '972501234567@c.us',
        ]);
    }

    /** מעבר ל"בטיפול" שולח ללקוח עדכון. */
    public function test_moving_a_ticket_into_work_updates_the_customer(): void
    {
        Queue::fake([SendTicketNotificationJob::class]);

        $ticket = $this->ticket();
        $ticket->update(['status' => TicketStatus::InProgress]);

        Queue::assertPushed(
            SendTicketNotificationJob::class,
            fn (SendTicketNotificationJob $job): bool => $job->ticketId === $ticket->id
                && $job->templateKey === 'ticket.in_progress',
        );
    }

    /** וההודעה עצמה יוצאת בערוץ של הפנייה. */
    public function test_the_update_reaches_the_customer_on_their_channel(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w1'])]);

        $ticket = $this->ticket();
        $ticket->update(['status' => TicketStatus::InProgress]);

        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.in_progress');

        Http::assertSent(fn ($request): bool => str_contains($request->data()['text'], 'בטיפול'));
    }

    /**
     * פנייה בטיפול אינה נספרת כפנייה שממתינה למענה.
     *
     * זו כל הנקודה: המונה בתפריט הוא מה שהצוות מסתכל עליו כדי לדעת כמה אנשים
     * מחכים לתשובה. פנייה שכבר ענינו עליה ואנחנו עובדים עליה אינה אחת מהם,
     * וספירתה ככזו הופכת את המספר לכזה שמפסיקים להאמין לו.
     */
    public function test_a_ticket_in_progress_is_not_counted_as_awaiting_a_reply(): void
    {
        Queue::fake();

        $waiting = $this->ticket();
        $working = $this->ticket();
        $working->update(['status' => TicketStatus::InProgress]);

        $this->assertSame(1, Ticket::where('status', TicketStatus::Open)->count());
        $this->assertSame($waiting->id, Ticket::where('status', TicketStatus::Open)->sole()->id);
    }

    /** אבל היא כן עדיין עבודה פתוחה — לא סגורה. */
    public function test_it_is_still_open_work(): void
    {
        Queue::fake();

        $ticket = $this->ticket();
        $ticket->update(['status' => TicketStatus::InProgress]);

        $this->assertNotContains($ticket->fresh()->status, Ticket::TERMINAL);
    }

    /** תשובת לקוח מחזירה פנייה בטיפול למצב פתוח — הכדור שוב אצלנו. */
    public function test_a_customer_reply_reopens_it(): void
    {
        Queue::fake();

        $ticket = $this->ticket();
        $ticket->update(['status' => TicketStatus::InProgress]);

        app(TicketIntake::class)->recordInbound(
            channel: TicketChannel::Whatsapp,
            messageChannel: MessageChannel::Whatsapp,
            customer: $ticket->customer,
            body: 'עוד משהו',
            threadRef: '972501234567@c.us',
            externalMessageId: 'in-1',
        );

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    /*
    | ----------------------------------------------------------------
    | הפעולות המהירות ברשימה
    | ----------------------------------------------------------------
    */

    /** פעולה מהירה על שורה ברשימת הפניות. */
    public function test_the_list_can_take_a_ticket_into_work(): void
    {
        Queue::fake([SendTicketNotificationJob::class]);
        $this->actingAs(User::factory()->create());

        $ticket = $this->ticket();

        Livewire::test(ListTickets::class)
            ->callTableAction('startWork', $ticket);

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        Queue::assertPushed(SendTicketNotificationJob::class);
    }

    /**
     * ופעולה קבוצתית — כל לקוח מעודכן.
     *
     * שלא כמו הסגירה השקטה לידה, שנעשית בעדכון שאילתה כדי שלא תישלח הודעה,
     * כאן ההודעה היא כל העניין — ולכן כל פנייה נשמרת בנפרד ועוברת דרך המאזין.
     */
    public function test_several_tickets_can_be_taken_into_work_at_once(): void
    {
        Queue::fake([SendTicketNotificationJob::class]);
        $this->actingAs(User::factory()->create());

        $first = $this->ticket();
        $second = $this->ticket();
        $done = $this->ticket();
        $done->update(['status' => TicketStatus::Resolved]);

        Livewire::test(ListTickets::class)
            ->callTableBulkAction('startWork', [$first, $second, $done]);

        $this->assertSame(TicketStatus::InProgress, $first->fresh()->status);
        $this->assertSame(TicketStatus::InProgress, $second->fresh()->status);
        // פנייה שכבר טופלה אינה נפתחת מחדש בטעות.
        $this->assertSame(TicketStatus::Resolved, $done->fresh()->status);

        // שתי הודעות "בטיפול" בדיוק. (הודעת הסגירה שנשלחה בהכנת הבדיקה על
        // הפנייה השלישית אינה נספרת כאן.)
        $this->assertSame(2, Queue::pushed(SendTicketNotificationJob::class)
            ->filter(fn (SendTicketNotificationJob $job): bool => $job->templateKey === 'ticket.in_progress')
            ->count());
    }

    /**
     * בחירה שכולה פניות שכבר בטיפול אינה מדווחת שלקוחות עודכנו.
     *
     * זה מצב שכיח דווקא — פניות בטיפול נמצאות בתור ברירת המחדל של הרשימה —
     * והודעת הצלחה קבועה הייתה מבטיחה עדכון ללקוחות שלא נשלח לאיש.
     */
    public function test_a_selection_with_nothing_to_change_says_so(): void
    {
        Queue::fake([SendTicketNotificationJob::class]);
        $this->actingAs(User::factory()->create());

        $already = $this->ticket();
        $already->update(['status' => TicketStatus::InProgress]);

        Livewire::test(ListTickets::class)
            ->callTableBulkAction('startWork', [$already]);

        // הודעה אחת בלבד — זו שנשלחה בהכנת הבדיקה, ולא עוד אחת מהפעולה.
        $this->assertSame(1, Queue::pushed(SendTicketNotificationJob::class)
            ->filter(fn (SendTicketNotificationJob $job): bool => $job->templateKey === 'ticket.in_progress')
            ->count());
    }

    /*
    | ----------------------------------------------------------------
    | הודעת הסגירה
    | ----------------------------------------------------------------
    */

    /**
     * הודעת סגירה אינה נפתחת באישור קבלה.
     *
     * "קיבלנו את פנייתך" בהודעה שמודיעה שהפנייה טופלה קוראת ללקוח כאילו רק
     * עכשיו פתחנו אותה — אחרי שכבר נסגרה.
     */
    public function test_a_receipt_opening_is_recognised(): void
    {
        $this->assertTrue(SendTicketNotificationJob::opensWithReceipt('קיבלנו את פנייתך #12 ואנחנו על זה.'));
        $this->assertTrue(SendTicketNotificationJob::opensWithReceipt("שלום דני,\nקיבלתי את פנייתך בנושא האתר."));
        $this->assertTrue(SendTicketNotificationJob::opensWithReceipt('פנייתך התקבלה במערכת.'));

        // פתיחים לגיטימיים לסגירה — אלה חייבים לעבור.
        $this->assertFalse(SendTicketNotificationJob::opensWithReceipt('שלום דני, טיפלנו בבעיית המהירות באתר והכל עובד.'));
        $this->assertFalse(SendTicketNotificationJob::opensWithReceipt('תודה שפנית אלינו — הבעיה נפתרה.'));
        // אזכור מאוחר בגוף ההודעה אינו פתיח.
        $this->assertFalse(SendTicketNotificationJob::opensWithReceipt(
            'שלום דני, הבעיה טופלה במלואה והאתר חזר לפעול כרגיל. שוב תודה על הסבלנות, ועל כך שקיבלנו את פנייתך מיד עם הזיהוי.'
        ));
    }
}
