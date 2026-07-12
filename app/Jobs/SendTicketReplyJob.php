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
use Illuminate\Support\Facades\Storage;

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
            $externalId = null;

            if (trim($body) !== '') {
                $externalId = $waha->sendMessage($chatId, $body)['id'] ?? null;
            }

            // Each attachment is sent as its own file message (base64 — WAHA
            // never needs to reach our server).
            foreach ($message->attachments ?? [] as $file) {
                if (($contents = $this->fileContents($file)) !== null) {
                    $sent = $waha->sendFile($chatId, $file['name'] ?? 'file', $file['mime'] ?? 'application/octet-stream', $contents);
                    $externalId ??= $sent['id'] ?? null;
                }
            }

            $message->update(['external_message_id' => $externalId]);
        } else {
            $email = $ticket->customer?->email;

            if (! $email) {
                return;
            }

            $body = $this->withSignature($message->body, (string) config('billing.notifications.reply_signature'));
            Mail::to($email)->send(new TicketReplyMail($ticket->subject, $body, $message->attachments ?? []));
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

    /**
     * Read a stored attachment's bytes, or null if it's gone.
     *
     * @param  array{path?: string, disk?: string}  $file
     */
    private function fileContents(array $file): ?string
    {
        $disk = Storage::disk($file['disk'] ?? (string) config('billing.support.attachments.disk'));

        return isset($file['path']) && $disk->exists($file['path']) ? $disk->get($file['path']) : null;
    }
}
