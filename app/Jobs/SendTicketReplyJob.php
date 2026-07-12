<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Mail\TicketReplyMail;
use App\Models\TicketMessage;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Route an agent's outbound ticket message back to the customer's original
 * channel (WhatsApp via WAHA, or email). Internal notes are never sent.
 */
class SendTicketReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $ticketMessageId) {}

    public function handle(WahaClient $waha): void
    {
        $message = TicketMessage::with('ticket.customer')->find($this->ticketMessageId);

        if (! $message
            || $message->direction !== MessageDirection::Outbound
            || $message->channel === MessageChannel::InternalNote
            || $message->external_message_id !== null) {
            return;
        }

        $ticket = $message->ticket;

        if ($message->channel === MessageChannel::Whatsapp) {
            $chatId = $ticket->external_thread_ref
                ?? $ticket->customer?->whatsapp_jid
                ?? $ticket->customer?->phone;

            if (! $chatId) {
                return;
            }

            $body = $this->withSignature($message->body, (string) config('billing.notifications.reply_signature_whatsapp'));
            $response = $waha->sendMessage($chatId, $body);
            $message->update(['external_message_id' => $response['id'] ?? null]);
        } else {
            $email = $ticket->customer?->email;

            if (! $email) {
                return;
            }

            $body = $this->withSignature($message->body, (string) config('billing.notifications.reply_signature'));
            Mail::to($email)->send(new TicketReplyMail($ticket->subject, $body));
            $message->update(['external_message_id' => 'mail-'.$message->id]);
        }

        if ($ticket->first_response_at === null) {
            $ticket->update(['first_response_at' => now()]);
        }
    }

    /**
     * Append the configured reply signature to the delivered message, separated
     * by a blank line. The stored internal message stays as the agent typed it;
     * the signature is boilerplate added only on the way out. Empty signature =
     * unchanged body.
     */
    private function withSignature(string $body, string $signature): string
    {
        $signature = trim($signature);

        return $signature === '' ? $body : $body."\n\n".$signature;
    }
}
