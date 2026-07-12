<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Jobs\IngestWhatsappMessageJob;
use App\Jobs\SendTicketReplyJob;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
            'billing.waha.owner_number' => '0501112222', // → 972501112222@c.us
            'billing.waha.default_country_code' => '972',
        ]);
    }

    private function ticketWithCustomer(): Ticket
    {
        $customer = Customer::factory()->create();

        return Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'האתר איטי',
            'status' => TicketStatus::Open,
            'external_thread_ref' => '972509999999@c.us',
        ]);
    }

    public function test_propose_notifies_the_owner_on_whatsapp_with_approval_instructions(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w'])]);
        $ticket = $this->ticketWithCustomer();

        $action = app(ApprovalGate::class)->propose('ticket_reply', 'תשובה מוצעת', ['reply' => 'שלום'], $ticket->customer_id, $ticket->id);

        $this->assertSame(ActionStatus::Pending, $action->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && $request->data()['chatId'] === '972501112222@c.us'
            && str_contains($request->data()['text'], "אשר {$action->id}"));
    }

    public function test_owner_approval_by_whatsapp_executes_the_reply_and_skips_ticket_intake(): void
    {
        Queue::fake([SendTicketReplyJob::class]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w'])]);

        $ticket = $this->ticketWithCustomer();
        $action = PendingAction::create([
            'type' => 'ticket_reply', 'status' => ActionStatus::Pending,
            'customer_id' => $ticket->customer_id, 'ticket_id' => $ticket->id,
            'summary' => 'תשובה', 'payload' => ['reply' => 'הבעיה טופלה, האתר חזר לעבוד.'],
        ]);

        // The owner replies on WhatsApp with the approval command.
        [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', 'own-1', [
            'payload' => ['id' => 'own-1', 'from' => '972501112222@c.us', 'body' => "אשר {$action->id}"],
        ]);
        IngestWhatsappMessageJob::dispatchSync($event->id);

        $action->refresh();
        $this->assertSame(ActionStatus::Executed, $action->status);
        $this->assertNotNull($action->executed_at);

        // The approved reply became an outbound AI message on the ticket.
        $outbound = $ticket->messages()->where('direction', MessageDirection::Outbound)->first();
        $this->assertNotNull($outbound);
        $this->assertSame(MessageAuthor::Ai, $outbound->author);
        Queue::assertPushed(SendTicketReplyJob::class, fn ($job) => $job->ticketMessageId === $outbound->id);

        // The command did NOT open a ticket for the owner's message.
        $this->assertSame(1, Ticket::count());
    }

    public function test_owner_rejection_records_and_executes_nothing(): void
    {
        Queue::fake([SendTicketReplyJob::class]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w'])]);

        $ticket = $this->ticketWithCustomer();
        $action = PendingAction::create([
            'type' => 'ticket_reply', 'status' => ActionStatus::Pending,
            'ticket_id' => $ticket->id, 'summary' => 'x', 'payload' => ['reply' => 'y'],
        ]);

        $reply = app(ApprovalGate::class)->handleOwnerMessage('972501112222@c.us', "דחה {$action->id}");

        $this->assertStringContainsString('נדחתה', $reply);
        $this->assertSame(ActionStatus::Rejected, $action->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_regular_customer_message_is_not_intercepted(): void
    {
        // Same text as a command but from a non-owner chat → normal intake.
        $this->assertNull(app(ApprovalGate::class)->handleOwnerMessage('972503333333@c.us', 'אשר 5'));

        // Owner chatting normally (not a command) → normal intake too.
        $this->assertNull(app(ApprovalGate::class)->handleOwnerMessage('972501112222@c.us', 'מה קורה עם האתר?'));
    }

    public function test_a_stale_proposal_is_refused_instead_of_executed_late(): void
    {
        $ticket = $this->ticketWithCustomer();
        $action = PendingAction::create([
            'type' => 'ticket_reply', 'status' => ActionStatus::Pending,
            'ticket_id' => $ticket->id, 'summary' => 'x', 'payload' => ['reply' => 'y'],
        ]);
        $action->timestamps = false;
        $action->forceFill(['created_at' => now()->subDays(10)])->save();

        $result = app(ApprovalGate::class)->approve($action->fresh());

        $this->assertStringContainsString('פגת תוקף', $result);
        $this->assertSame(ActionStatus::Rejected, $action->fresh()->status);
        $this->assertSame(0, $ticket->messages()->count());
    }
}
