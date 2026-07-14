<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\NotificationType;
use App\Mail\TicketReplyMail;
use App\Models\NotificationLog;
use App\Models\TicketMessage;
use App\Services\Waha\WahaClient;
use App\Support\RichText;
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
            // Use external_thread_ref only when it's a real chat id (a WhatsApp
            // JID contains '@'); a Manual ticket's ref (e.g. "mgmt-…") is not one,
            // so fall back to the customer's saved WhatsApp/phone.
            $ref = $ticket->external_thread_ref;
            $chatId = (filled($ref) && str_contains($ref, '@'))
                ? $ref
                : ($ticket->customer?->whatsapp_jid ?? $ticket->customer?->phone);

            if (! $chatId) {
                return;
            }

            // Rich replies were composed as HTML — convert to WhatsApp markup so
            // formatting survives and no raw tags reach the customer.
            $text = filled($message->body_html) ? RichText::toWhatsapp($message->body_html) : $message->body;
            $body = $this->withSignature($text, (string) config('billing.notifications.reply_signature_whatsapp'));
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
            NotificationLog::record('whatsapp', NotificationType::TicketReply, $chatId, null, $body, $ticket->customer?->id);
        } else {
            $email = $ticket->customer?->email;

            if (! $email) {
                return;
            }

            $body = $this->withSignature($message->body, (string) config('billing.notifications.reply_signature'));
            // Deliver the agent's formatting as HTML when present (signature is
            // appended as a plain paragraph); otherwise plain text.
            $bodyHtml = filled($message->body_html)
                ? $this->htmlWithSignature($message->body_html, (string) config('billing.notifications.reply_signature'))
                : null;
            // Tag the subject so the customer's reply threads back onto this ticket.
            $subject = $ticket->subject.' '.$ticket->emailTag();
            Mail::to($email)->send(new TicketReplyMail($subject, $body, $message->attachments ?? [], $bodyHtml));
            $message->update(['external_message_id' => 'mail-'.$message->id]);
            NotificationLog::record('email', NotificationType::TicketReply, $email, $subject, $body, $ticket->customer?->id);
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
     * Append the signature to an HTML body as its own paragraph (escaped, line
     * breaks preserved), so the delivered email keeps the agent's formatting.
     */
    private function htmlWithSignature(string $html, string $signature): string
    {
        $signature = trim($signature);

        if ($signature === '') {
            return $html;
        }

        return $html.'<p>'.nl2br(e($signature)).'</p>';
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
