<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotificationJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Two things the customer reads: the balance we put in front of them when they
 * write in, and what we tell them we did when we close the ticket.
 */
class TicketAckDebtAndSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'name' => 'דנה', 'email' => 'dana@example.com', 'phone' => '0501234567',
        ], $attributes));
    }

    private function emailTicket(Customer $customer, ?string $from = null): Ticket
    {
        return Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'האתר איטי',
            'status' => TicketStatus::Open,
            'contact_handle' => $from ?? $customer->email,
        ]);
    }

    private function demandedCharge(Customer $customer, int $totalAgorot, string $description): Charge
    {
        return Charge::create([
            'customer_id' => $customer->id,
            'amount_agorot' => $totalAgorot, 'vat_agorot' => 0, 'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending, 'attempt_number' => 1,
            'description' => $description,
            'period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->toDateString(),
            'demand_sent_at' => now()->subDays(3),
            'cardcom_pay_url' => 'https://secure.cardcom.solutions/pay/abc',
        ]);
    }

    /** Send the acknowledgement and return the body the customer received. */
    private function sendAck(Ticket $ticket): string
    {
        Mail::fake();

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))
            ->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));

        $body = '';
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use (&$body): bool {
            $body = $mail->bodyText;

            return true;
        });

        return $body;
    }

    public function test_an_open_balance_reaches_the_customer_with_a_link_to_pay_it(): void
    {
        $customer = $this->customer();
        $this->demandedCharge($customer, 24000, 'אחסון שנתי');

        $body = $this->sendAck($this->emailTicket($customer));

        // The amount, and a way to act on it, in the message they are already reading.
        $this->assertStringContainsString('240', $body);
        $this->assertStringContainsString('אחסון שנתי', $body);
        $this->assertStringContainsString('/pay/', $body);
        // And it is clear this is not an answer to what they wrote about.
        $this->assertStringContainsString('אינו קשור לפנייה', $body);
    }

    public function test_the_balance_is_not_sent_to_whoever_happened_to_write_in(): void
    {
        $customer = $this->customer();
        $this->demandedCharge($customer, 24000, 'אחסון שנתי');

        // The ticket belongs to the customer, but their web developer opened it.
        // "You owe ₪240" to that address is a disclosure nobody authorised.
        $body = $this->sendAck($this->emailTicket($customer, 'dev@agency.example'));

        $this->assertStringNotContainsString('240', $body);
        $this->assertStringNotContainsString('/pay/', $body);
    }

    public function test_a_pending_charge_nobody_demanded_is_not_called_a_debt(): void
    {
        $customer = $this->customer();
        $charge = $this->demandedCharge($customer, 24000, 'אחסון שנתי');

        // Mid-processing, not money we asked for. Naming it would be a demand
        // nobody decided to send.
        $charge->update(['demand_sent_at' => null]);

        $this->assertStringNotContainsString('/pay/', $this->sendAck($this->emailTicket($customer)));
    }

    public function test_a_customer_who_owes_nothing_gets_an_ordinary_acknowledgement(): void
    {
        $body = $this->sendAck($this->emailTicket($this->customer()));

        $this->assertStringNotContainsString('יתרה פתוחה', $body);
    }

    public function test_the_balance_can_be_switched_off(): void
    {
        config(['billing.notifications.debt_in_ticket_ack' => false]);

        $customer = $this->customer();
        $this->demandedCharge($customer, 24000, 'אחסון שנתי');

        $this->assertStringNotContainsString('/pay/', $this->sendAck($this->emailTicket($customer)));
    }

    public function test_the_closing_summary_is_written_from_what_actually_happened(): void
    {
        config(['billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true]);

        $customer = $this->customer();
        $ticket = $this->emailTicket($customer);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Customer, 'body' => 'האתר נטען לאט מאוד מאתמול.',
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Agent, 'body' => 'החלפנו את תוסף המטמון והעברנו את התמונות ל-WebP. זמן הטעינה ירד ל-1.4 שניות.',
            'external_message_id' => 'mail-7',   // delivered
        ]);
        // An internal note and an unsent AI draft share the internal channel.
        // Neither may reach the prompt: one is the team talking to itself, the
        // other is an answer the customer never received.
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::InternalNote,
            'author' => MessageAuthor::Agent, 'body' => 'הלקוחה מעצבנת, לגבות עליה תוספת בפעם הבאה.',
        ]);

        $seen = '';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()
            ->with(Mockery::any(), Mockery::on(function (string $prompt) use (&$seen): bool {
                $seen = $prompt;

                return true;
            }), Mockery::any())
            ->andReturn(['message' => 'החלפנו את תוסף המטמון, והאתר נטען כעת ב-1.4 שניות. פנייה #1']);

        Mail::fake();
        (new SendTicketNotificationJob($ticket->id, 'ticket.resolved'))
            ->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        // What the team actually did is in front of the model — previously it
        // saw only the opening line and could do no better than "טופל".
        $this->assertStringContainsString('WebP', $seen);
        $this->assertStringContainsString('נציג:', $seen);
        // And the internal remark is not.
        $this->assertStringNotContainsString('מעצבנת', $seen);
    }

    public function test_a_contact_whose_email_reads_like_the_phone_number_is_still_a_stranger(): void
    {
        $customer = $this->customer(['phone' => '0501234567']);
        $this->demandedCharge($customer, 24000, 'אחסון שנתי');

        // Digits-only comparison exists for phone numbers and WhatsApp ids, and
        // it used to be applied to everything — so this address matched the
        // customer's phone and was handed the balance.
        $body = $this->sendAck($this->emailTicket($customer, '0501234567@example.com'));

        $this->assertStringNotContainsString('/pay/', $body);
    }

    public function test_a_reply_that_has_not_left_yet_is_not_summarised_as_said(): void
    {
        config(['billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true]);

        $customer = $this->customer();
        $ticket = $this->emailTicket($customer);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Customer, 'body' => 'האתר נטען לאט.',
            'external_message_id' => 'in-1',
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Agent, 'body' => 'העברנו את האתר לשרת מהיר יותר.',
            'external_message_id' => 'mail-9',
        ]);
        // Queued or retrying after a provider failure — the customer does not
        // have this one.
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Agent, 'body' => 'והוספנו לך גיבוי יומי בחינם.',
            'external_message_id' => null,
        ]);

        $seen = '';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()
            ->with(Mockery::any(), Mockery::on(function (string $prompt) use (&$seen): bool {
                $seen = $prompt;

                return true;
            }), Mockery::any())
            ->andReturn(['message' => 'העברנו את האתר לשרת מהיר יותר. פנייה #1']);

        Mail::fake();
        (new SendTicketNotificationJob($ticket->id, 'ticket.resolved'))
            ->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        $this->assertStringContainsString('שרת מהיר יותר', $seen);
        $this->assertStringNotContainsString('גיבוי יומי', $seen);
    }

    public function test_a_stranger_on_the_thread_is_not_quoted_as_the_customer(): void
    {
        config(['billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true]);

        $customer = $this->customer();
        $ticket = $this->emailTicket($customer);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Customer, 'body' => 'האתר נטען לאט.',
            'external_message_id' => 'in-1',
        ]);
        // A tagged thread lets anyone who replies join it, and intake records
        // who. "הכול תקין אצלי" from the bookkeeper is not the customer saying
        // their site is fixed.
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Customer, 'body' => 'הכול תקין אצלי.',
            'sender_label' => 'רו״ח חיצוני', 'external_message_id' => 'in-2',
        ]);

        $seen = '';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()
            ->with(Mockery::any(), Mockery::on(function (string $prompt) use (&$seen): bool {
                $seen = $prompt;

                return true;
            }), Mockery::any())
            ->andReturn(['message' => 'הטיפול בפנייה #1 הושלם.']);

        Mail::fake();
        (new SendTicketNotificationJob($ticket->id, 'ticket.resolved'))
            ->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        $this->assertStringContainsString('רו״ח חיצוני: הכול תקין', $seen);
    }

    public function test_the_closing_message_is_told_not_to_invent_a_resolution(): void
    {
        config(['billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true]);

        $customer = $this->customer();
        $ticket = $this->emailTicket($customer);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Customer, 'body' => 'האתר נטען לאט.',
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'author' => MessageAuthor::Agent, 'body' => 'בדקנו, תודה.',
        ]);

        $seen = '';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()
            ->with(Mockery::on(function (string $system) use (&$seen): bool {
                $seen = $system;

                return true;
            }), Mockery::any(), Mockery::any())
            ->andReturn(['message' => 'הטיפול בפנייה #1 הושלם.']);

        Mail::fake();
        (new SendTicketNotificationJob($ticket->id, 'ticket.resolved'))
            ->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        // A summary of work that was never done is far worse than a vague one:
        // the customer closes the matter believing something happened.
        $this->assertStringContainsString('אסור בהחלט להמציא פעולות', $seen);
        // And the acknowledgement's "never promise a solution" must not be the
        // rule here — describing the delivered solution IS the job.
        $this->assertStringNotContainsString('אל תפתור אותה', $seen);
    }

    public function test_the_balance_is_not_appended_to_a_closing_message(): void
    {
        $customer = $this->customer();
        $this->demandedCharge($customer, 24000, 'אחסון שנתי');
        $ticket = $this->emailTicket($customer);

        Mail::fake();
        (new SendTicketNotificationJob($ticket->id, 'ticket.resolved'))
            ->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));

        $body = '';
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use (&$body): bool {
            $body = $mail->bodyText;

            return true;
        });

        // "We fixed your site — now pay up" reads as a bill for the fix.
        $this->assertStringNotContainsString('/pay/', $body);
    }
}
