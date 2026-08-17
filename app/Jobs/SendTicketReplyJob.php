<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\NotificationType;
use App\Mail\TicketReplyMail;
use App\Models\NotificationLog;
use App\Models\TicketMessage;
use App\Services\Waha\WahaClient;
use App\Support\EmailBody;
use App\Support\RichText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
                $sent = $waha->sendMessage($chatId, $body);

                // MARK IT SENT NOW, before anything else can fail.
                //
                // The guard at the top of this job is the only thing standing
                // between a retry and a second copy of the same message in the
                // customer's chat — and until this write lands, that guard is
                // open. It used to be written at the END of the WhatsApp branch,
                // which meant every line in between (the attachment loop, a
                // provider id in an unexpected shape) was a chance to fail after
                // the customer had already been messaged, and be answered with a
                // retry that sent it all again.
                //
                // The value is ours and always storable; the provider's own id is
                // a nicety, filled in below if it can be read at all.
                $this->markSent($message, 'wa-'.$message->id);

                $externalId = $this->providerId($sent);
            }

            // Each attachment is sent as its own file message (base64 — WAHA
            // never needs to reach our server). One failing file is logged and
            // skipped rather than aborting the whole reply — otherwise the text,
            // already sent above, would be re-sent as a duplicate on retry.
            foreach ($message->attachments ?? [] as $file) {
                if (($contents = $this->fileContents($file)) === null) {
                    continue;
                }

                try {
                    $sentFile = $waha->sendFile($chatId, $file['name'] ?? 'file', $file['mime'] ?? 'application/octet-stream', $contents);
                    $this->markSent($message, 'wa-'.$message->id);
                    $externalId ??= $this->providerId($sentFile);
                } catch (\Throwable $e) {
                    Log::warning('SendTicketReplyJob: WhatsApp attachment failed', [
                        'ticket' => $ticket->id,
                        'file' => $file['name'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Upgrade the marker to the provider's own id when we got one — it is
            // what ties this row to the message in WhatsApp. Best-effort: the
            // message is already marked as sent, so failing here costs a
            // traceability id, never a second copy.
            if ($externalId !== null) {
                $this->markSent($message, $externalId);
            }

            NotificationLog::record('whatsapp', NotificationType::TicketReply, $chatId, null, $body, $ticket->customer?->id);
        } else {
            // The person who wrote the ticket, with the business copied — not
            // the business alone. See Ticket::replyToEmail().
            $email = $ticket->replyToEmail();
            $cc = $ticket->replyCcEmails();

            // No address of our own but someone IS copied — the conversation is
            // being held with them, so they become the recipient rather than a
            // copy of a message sent to nobody.
            if (! $email) {
                if ($cc === []) {
                    return;
                }

                $email = array_shift($cc);
            }

            $body = $this->withSignature($message->body, (string) config('billing.notifications.reply_signature'));
            // Deliver the agent's formatting as HTML when present (signature is
            // appended as a plain paragraph); otherwise plain text.
            $bodyHtml = filled($message->body_html)
                ? $this->htmlWithSignature($message->body_html, (string) config('billing.notifications.reply_signature'))
                : null;
            // Tag the subject so the customer's reply threads back onto this ticket.
            $subject = $ticket->subject.' '.$ticket->emailTag();
            // The copied addresses get the same tagged subject, so their reply
            // threads back onto this ticket exactly like the customer's does.
            Mail::to($email)->cc($cc)->send(new TicketReplyMail($subject, $body, $message->attachments ?? [], $bodyHtml));
            // Same rule as WhatsApp: once it has left, nothing that follows may
            // reopen the door to a retry that sends it again.
            $this->markSent($message, 'mail-'.$message->id);
            NotificationLog::record('email', NotificationType::TicketReply, $email, $subject, $body, $ticket->customer?->id);
        }

        if ($ticket->first_response_at === null) {
            $ticket->update(['first_response_at' => now()]);
        }
    }

    /**
     * Stamp the "already delivered" marker, swallowing anything that goes wrong.
     *
     * This write is the duplicate guard, so it must not be able to fail loudly:
     * an exception here would be answered by a retry that sends the customer a
     * second copy — the exact outcome the marker exists to prevent. A marker
     * that could not be written is worth a log line, not a repeated message.
     */
    private function markSent(TicketMessage $message, string $externalId): void
    {
        try {
            $message->update(['external_message_id' => $this->storableId($externalId)]);
        } catch (\Throwable $e) {
            Log::warning('SendTicketReplyJob: could not stamp the sent marker', [
                'message' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The provider's message id, whatever shape the WAHA engine returned it in.
     *
     * It is a plain string on some engines, an object on others (WEBJS returns
     * `id: {_serialized: "true_972…@c.us_3EB0…", …}`), and absent on others
     * still (NOWEB answers with `key`). Reading it as a string worked only on
     * the first of those, and the value ends up in a string column — so it is
     * flattened here rather than at the point of use.
     *
     * @param  array<string, mixed>  $response
     */
    private function providerId(array $response): ?string
    {
        $id = $response['id'] ?? $response['key'] ?? null;

        if (is_array($id)) {
            $id = $id['_serialized'] ?? $id['id'] ?? null;
        }

        if (is_array($id) || is_object($id) || $id === null) {
            return null;
        }

        $id = trim((string) $id);

        return $id !== '' ? $id : null;
    }

    /** The provider's id, cut to what the column can hold (null stays null). */
    private function storableId(?string $externalId): ?string
    {
        return $externalId === null ? null : mb_substr($externalId, 0, 500);
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
     *
     * Highlights are given their colour here, on the way out: a mail client has
     * no stylesheet of ours to consult.
     */
    private function htmlWithSignature(string $html, string $signature): string
    {
        $html = EmailBody::inlineHighlights($html);
        $signature = trim($signature);

        return $signature === '' ? $html : $html.'<p>'.nl2br(e($signature)).'</p>';
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
