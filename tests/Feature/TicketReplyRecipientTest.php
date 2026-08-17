<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketReplyJob;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketWatcher;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * תשובה מגיעה למי שכתב.
 *
 * עסק אינו פותח פניות — יוסי מהמשרד פותח, או רו״ח חיצוני, או עובד שראה שהאתר
 * נפל. שליחת התשובה לתיבה הראשית של העסק מוסרת אותה למי שלא שאל, ומשאירה את מי
 * ששאל ממתין לתשובה שכבר נשלחה. מבחינתו התמיכה פשוט לא ענתה — והוא צודק.
 */
class TicketReplyRecipientTest extends TestCase
{
    use RefreshDatabase;

    private function emailTicket(array $attributes = []): Ticket
    {
        $customer = Customer::factory()->create(['email' => 'office@customer.co.il']);

        return Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'האתר לא עולה',
            'status' => TicketStatus::Open,
            'contact_handle' => 'yossi@customer.co.il',
            'contact_name' => 'יוסי',
            ...$attributes,
        ]);
    }

    private function reply(Ticket $ticket): TicketMessage
    {
        return TicketMessage::create([
            'ticket_id' => $ticket->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'טיפלנו בבעיה.',
            'external_message_id' => null,
        ]);
    }

    /** מי שפתח את הפנייה הוא הנמען, ולא התיבה הראשית של העסק. */
    public function test_the_reply_is_addressed_to_the_person_who_wrote(): void
    {
        Mail::fake();
        $ticket = $this->emailTicket();

        (new SendTicketReplyJob($this->reply($ticket)->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => $mail->hasTo('yossi@customer.co.il'));
    }

    /**
     * והעסק נשאר בעותק.
     *
     * התיבה הראשית קיבלה עד היום כל תשובה, ותיקון של *למי* התשובה ממוענת אינו
     * רשאי להסתיר את השיחה מבעל החשבון — זה היה מחליף כשל שקט אחד באחר.
     */
    public function test_the_business_stays_copied(): void
    {
        Mail::fake();
        $ticket = $this->emailTicket();

        (new SendTicketReplyJob($this->reply($ticket)->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => $mail->hasCc('office@customer.co.il'));
    }

    /** פנייה שנפתחה מהפאנל או בטלפון — אין כתובת של פונה, והעסק הוא הנמען. */
    public function test_a_ticket_with_no_email_handle_falls_back_to_the_business(): void
    {
        Mail::fake();
        $ticket = $this->emailTicket(['contact_handle' => '0501234567']);

        (new SendTicketReplyJob($this->reply($ticket)->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => $mail->hasTo('office@customer.co.il'));
    }

    /**
     * וכשהכותב הוא בעל התיבה הראשית — הוא אינו מקבל את התשובה פעמיים.
     *
     * עותק לעצמך נקרא כמערכת ששכחה עם מי היא מדברת.
     */
    public function test_the_writer_is_not_copied_on_their_own_reply(): void
    {
        Mail::fake();
        $ticket = $this->emailTicket(['contact_handle' => 'office@customer.co.il']);

        (new SendTicketReplyJob($this->reply($ticket)->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail): bool {
            return $mail->hasTo('office@customer.co.il') && ! $mail->hasCc('office@customer.co.il');
        });
    }

    /** צופים בפנייה ממשיכים לקבל עותק, בלי כפילות מול הנמען. */
    public function test_watchers_are_still_copied_once(): void
    {
        Mail::fake();
        $ticket = $this->emailTicket();
        TicketWatcher::create(['ticket_id' => $ticket->id, 'email' => 'accountant@external.co.il']);
        TicketWatcher::create(['ticket_id' => $ticket->id, 'email' => 'yossi@customer.co.il']);

        (new SendTicketReplyJob($this->reply($ticket)->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail): bool {
            return $mail->hasCc('accountant@external.co.il') && ! $mail->hasCc('yossi@customer.co.il');
        });
    }

    /** פונה לא מזוהה — אין לקוח כלל, והתשובה עדיין מגיעה אליו. */
    public function test_an_unidentified_sender_still_gets_an_answer(): void
    {
        Mail::fake();
        $ticket = Ticket::create([
            'customer_id' => null,
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה',
            'status' => TicketStatus::Open,
            'contact_handle' => 'stranger@example.com',
        ]);

        (new SendTicketReplyJob($this->reply($ticket)->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => $mail->hasTo('stranger@example.com'));
    }
}
