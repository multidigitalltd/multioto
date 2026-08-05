<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Jobs\NotifyTicketWatchersJob;
use App\Jobs\SendTicketReplyJob;
use App\Mail\NotificationMail;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\AgentReply;
use App\Services\Support\TicketIntake;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * מכותבים בפנייה — כתובת נוספת שמקבלת את ההתכתבות ויכולה לענות לתוכה.
 *
 * רואה החשבון של הלקוח בשאלת חיוב, ספק בהעברת אתר, קולגה שצריך להישאר בתמונה.
 * המכותב שייך לפנייה אחת בלבד: להיות מכותב בשאלה על חשבונית אינו הסכמה לקרוא
 * כל מה שהלקוח הזה אי פעם כתב לנו.
 */
class TicketWatchersTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(?Customer $customer = null): Ticket
    {
        return Ticket::create([
            'customer_id' => ($customer ?? Customer::factory()->create(['email' => 'lakoach@example.com']))->id,
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה על החשבונית',
            'status' => TicketStatus::Open,
        ]);
    }

    /** תשובה ללקוח מגיעה גם למכותב, עם אותה כותרת ממותגת שמאפשרת לו לענות. */
    public function test_a_reply_copies_the_watchers(): void
    {
        Mail::fake();
        $ticket = $this->ticket();
        $ticket->watchers()->create(['email' => 'roeh@example.com', 'name' => 'רואה חשבון']);

        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'שלום, מצורפת החשבונית',
            'author' => MessageAuthor::Agent,
        ]);

        (new SendTicketReplyJob($message->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail) use ($ticket): bool {
            return $mail->hasTo('lakoach@example.com')
                && $mail->hasCc('roeh@example.com')
                && str_contains($mail->subjectLine, $ticket->emailTag());
        });
    }

    /** הלקוח עצמו לעולם לא מכותב על עצמו — אחרת כל תשובה מגיעה אליו פעמיים. */
    public function test_the_customer_is_never_copied_on_their_own_ticket(): void
    {
        $ticket = $this->ticket();
        $ticket->watchers()->create(['email' => 'LAKOACH@example.com']);

        $this->assertSame([], $ticket->watcherEmails());
    }

    /** פנייה בלי מייל של לקוח אבל עם מכותב — ההתכתבות מתנהלת מולו. */
    public function test_a_watcher_becomes_the_recipient_when_there_is_no_customer_address(): void
    {
        Mail::fake();
        $ticket = $this->ticket(Customer::factory()->create(['email' => null]));
        $ticket->watchers()->create(['email' => 'roeh@example.com']);

        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'עדכון',
            'author' => MessageAuthor::Agent,
        ]);

        (new SendTicketReplyJob($message->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => $mail->hasTo('roeh@example.com'));
    }

    /**
     * תשובת הלקוח מועברת למכותב. מכותב שרואה רק את הצד שלנו במצב גרוע יותר
     * ממי שלא כותב כלל — הוא קורא תשובה לשאלה שאינו רואה.
     */
    public function test_an_inbound_message_is_forwarded_to_the_watchers(): void
    {
        Mail::fake();
        $ticket = $this->ticket();
        $ticket->watchers()->create(['email' => 'roeh@example.com']);

        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'תודה, קיבלתי',
            'author' => MessageAuthor::Customer,
        ]);

        (new NotifyTicketWatchersJob($message->id))->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('roeh@example.com')
            && str_contains($mail->subjectLine, $ticket->emailTag()));
    }

    /** מכותב אינו מקבל בחזרה את ההודעה שהוא עצמו שלח. */
    public function test_a_watcher_does_not_receive_their_own_message_back(): void
    {
        Mail::fake();
        $ticket = $this->ticket();
        $ticket->watchers()->create(['email' => 'roeh@example.com']);

        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'שאלה מרואה החשבון',
            'author' => MessageAuthor::Customer,
        ]);

        (new NotifyTicketWatchersJob($message->id, 'roeh@example.com'))->handle();

        Mail::assertNothingSent();
    }

    /**
     * הודעה שנכנסה מכתובת שאינה הלקוח נושאת את שם השולח האמיתי. לייחס את
     * מילותיו ללקוח זה שקר שקט באמצע התכתבות תמיכה.
     */
    public function test_an_inbound_message_from_someone_else_carries_their_name(): void
    {
        Queue::fake();
        $ticket = $this->ticket();

        $message = app(TicketIntake::class)->recordInbound(
            channel: TicketChannel::Email,
            messageChannel: MessageChannel::Email,
            customer: null,
            body: 'מדבר רואה החשבון',
            externalMessageId: 'msg-cc-1',
            subject: 'שאלה על החשבונית '.$ticket->emailTag(),
            contactName: 'רואה חשבון',
            contactHandle: 'roeh@example.com',
            threadTicketId: $ticket->id,
        );

        $this->assertSame($ticket->id, $message->ticket_id);
        $this->assertSame('רואה חשבון <roeh@example.com>', $message->sender_label);
    }

    /** והודעה של הלקוח עצמו נשארת נקייה — בלי שורת "מאת" מיותרת. */
    public function test_the_customers_own_message_carries_no_sender_label(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['email' => 'lakoach@example.com']);
        $ticket = $this->ticket($customer);

        $message = app(TicketIntake::class)->recordInbound(
            channel: TicketChannel::Email,
            messageChannel: MessageChannel::Email,
            customer: $customer,
            body: 'זה אני, הלקוח',
            externalMessageId: 'msg-own-1',
            subject: 'שאלה על החשבונית '.$ticket->emailTag(),
            contactHandle: 'lakoach@example.com',
            threadTicketId: $ticket->id,
        );

        $this->assertNull($message->sender_label);
    }

    /** המסך מוסיף מכותב, ומסיר אותו כשהוא יורד מהרשימה. */
    public function test_the_screen_adds_and_removes_watchers(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('manageWatchers', ['watchers' => [['email' => 'roeh@example.com', 'name' => 'רואה חשבון']]])
            ->assertHasNoActionErrors();

        $this->assertSame(['roeh@example.com'], $ticket->watchers()->pluck('email')->all());

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('manageWatchers', ['watchers' => []]);

        $this->assertSame([], $ticket->watchers()->pluck('email')->all());
    }

    /** כתובת פסולה נעצרת בטופס ולא נשמרת. */
    public function test_an_invalid_address_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('manageWatchers', ['watchers' => [['email' => 'לא כתובת']]])
            ->assertHasActionErrors();

        $this->assertSame(0, $ticket->watchers()->count());
    }

    /** מכותבים של פנייה אחת אינם מכותבים בפנייה אחרת של אותו לקוח. */
    public function test_a_watcher_is_scoped_to_one_ticket(): void
    {
        $customer = Customer::factory()->create(['email' => 'lakoach@example.com']);
        $billing = $this->ticket($customer);
        $other = $this->ticket($customer);

        $billing->watchers()->create(['email' => 'roeh@example.com']);

        $this->assertSame(['roeh@example.com'], $billing->watcherEmails());
        $this->assertSame([], $other->watcherEmails());
    }

    /** תשובה שנשלחת מהמסך מגיעה גם למכותב — אותו נתיב שליחה בדיוק. */
    public function test_an_agent_reply_from_the_panel_reaches_the_watcher(): void
    {
        Queue::fake();
        $ticket = $this->ticket();
        $ticket->watchers()->create(['email' => 'roeh@example.com']);

        app(AgentReply::class)->send($ticket, 'תשובה');

        Queue::assertPushed(SendTicketReplyJob::class);
        $this->assertSame(['roeh@example.com'], $ticket->watcherEmails());
    }
}
