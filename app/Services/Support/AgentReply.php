<?php

namespace App\Services\Support;

use App\Enums\ActionStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketReplyJob;
use App\Models\PendingAction;
use App\Models\Ticket;
use App\Models\TicketMessage;

/**
 * Send an agent's reply to a ticket's customer from OUTSIDE the panel — an email
 * reply to the team notification, or a "ענה #id" command in the WhatsApp group.
 * Centralises the same side effects the in-panel chat performs, so a reply
 * behaves identically wherever it originates.
 */
class AgentReply
{
    /**
     * Record and deliver an outbound reply on the ticket's own channel, move the
     * ticket to "waiting for customer", stamp the first response, and cancel any
     * pending AI-reply proposal (so it can't later be sent as a duplicate).
     */
    public function send(Ticket $ticket, string $body, ?string $bodyHtml = null): TicketMessage
    {
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => $ticket->channel === TicketChannel::Whatsapp
                ? MessageChannel::Whatsapp
                : MessageChannel::Email,
            'body' => $body,
            'body_html' => $bodyHtml,
            'author' => MessageAuthor::Agent,
        ]);

        $updates = [];
        if (in_array($ticket->status, [TicketStatus::Open, TicketStatus::Pending, TicketStatus::OnHold], true)) {
            $updates['status'] = TicketStatus::Pending;
        }
        if ($ticket->first_response_at === null) {
            $updates['first_response_at'] = now();
        }
        if ($updates !== []) {
            $ticket->update($updates);
        }

        // A manual reply supersedes any pending AI reply proposal for this ticket.
        PendingAction::where('ticket_id', $ticket->id)
            ->where('type', 'ticket_reply')
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Rejected, 'decided_at' => now(), 'error' => 'בוטלה — נשלחה תשובה ידנית.']);

        SendTicketReplyJob::dispatch($message->id);

        return $message;
    }
}
