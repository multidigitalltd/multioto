<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use App\Services\Support\AttachmentStore;
use App\Services\Support\TicketIntake;
use App\Support\EmailBody;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Turn an inbound email into a ticket message. Matches the sender to a customer
 * by address and threads replies on the same normalized subject together, so a
 * back-and-forth email conversation lands on a single ticket.
 */
class IngestEmailMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $webhookEventId) {}

    public function handle(TicketIntake $intake, AttachmentStore $attachments): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = $event->payload;

        $from = $this->normalizeEmail((string) ($payload['From'] ?? $payload['from'] ?? ''));
        $subject = trim((string) ($payload['Subject'] ?? $payload['subject'] ?? ''));
        // Keep the message's line structure: use the plain-text part, or convert
        // the HTML part to text (block tags → newlines) when it's HTML-only, so
        // the thread doesn't collapse into one unreadable run.
        $body = EmailBody::toText(
            $payload['TextBody'] ?? $payload['text'] ?? $payload['StrippedTextReply'] ?? null,
            $payload['HtmlBody'] ?? $payload['html'] ?? null,
        );
        $messageId = $payload['MessageID'] ?? $payload['message_id'] ?? null;

        if ($from === '') {
            $event->markProcessed();

            return;
        }

        $customer = $intake->matchCustomer(email: $from);

        // Keep the sender's identity for unidentified enquiries: display name
        // (from the "From" header) + the bare address.
        $fromName = trim((string) ($payload['FromName'] ?? ($payload['FromFull']['Name'] ?? '')));
        if ($fromName === '' && preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>\s*$/', (string) ($payload['From'] ?? $payload['from'] ?? ''), $m)) {
            $fromName = trim($m[1]);
        }

        $message = $intake->recordInbound(
            channel: TicketChannel::Email,
            messageChannel: MessageChannel::Email,
            customer: $customer,
            body: $body,
            threadRef: $this->threadRef($from, $subject),
            externalMessageId: $messageId,
            subject: $subject,
            contactName: $fromName ?: null,
            contactHandle: $from,
            // A reply keeps our [#id] tag in the subject → thread onto that ticket.
            threadTicketId: Ticket::idFromSubject($subject),
        );

        // Store any attachments (Postmark sends them base64-encoded inline) and
        // record their metadata on the just-created message. Only on first
        // ingest — recordInbound is idempotent per external id.
        if ($message->wasRecentlyCreated) {
            $stored = $this->storeAttachments($attachments, $message->ticket_id, $payload['Attachments'] ?? $payload['attachments'] ?? []);

            if ($stored !== []) {
                $message->update(['attachments' => $stored]);
            }
        }

        $event->markProcessed();
    }

    /**
     * Decode and store inbound email attachments (Postmark shape:
     * {Name, Content: base64, ContentType, ContentLength}). Rejected files are
     * simply skipped.
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @return array<int, array{name: string, mime: string, size: int, path: string, disk: string}>
     */
    protected function storeAttachments(AttachmentStore $store, int $ticketId, array $raw): array
    {
        $out = [];

        foreach ($raw as $attachment) {
            $encoded = (string) ($attachment['Content'] ?? $attachment['content'] ?? '');
            $contents = $encoded !== '' ? base64_decode($encoded, true) : false;

            if ($contents === false || $contents === '') {
                continue;
            }

            $meta = $store->store(
                $ticketId,
                (string) ($attachment['Name'] ?? $attachment['name'] ?? 'file'),
                $contents,
                (string) ($attachment['ContentType'] ?? $attachment['content_type'] ?? '') ?: null,
            );

            if ($meta !== null) {
                $out[] = $meta;
            }
        }

        return $out;
    }

    /**
     * Extract a bare address from a "Name <addr@host>" header, lowercased.
     */
    protected function normalizeEmail(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = $m[1];
        }

        return Str::lower(trim($raw));
    }

    /**
     * Stable per-conversation key: sender + subject with Re:/Fwd: prefixes and
     * casing stripped, so replies group onto the original ticket.
     */
    protected function threadRef(string $from, string $subject): string
    {
        $normalized = Str::lower(trim(preg_replace('/^((re|fw|fwd|תשובה|הועבר)\s*:\s*)+/iu', '', $subject)));

        return 'email:'.sha1($from.'|'.$normalized);
    }
}
