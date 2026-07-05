<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Models\WebhookEvent;
use App\Services\Support\TicketIntake;
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

    public function handle(TicketIntake $intake): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = $event->payload;

        $from = $this->normalizeEmail((string) ($payload['From'] ?? $payload['from'] ?? ''));
        $subject = trim((string) ($payload['Subject'] ?? $payload['subject'] ?? ''));
        $body = trim((string) ($payload['TextBody'] ?? $payload['text'] ?? $payload['StrippedTextReply'] ?? ''));
        $messageId = $payload['MessageID'] ?? $payload['message_id'] ?? null;

        if ($from === '') {
            $event->markProcessed();

            return;
        }

        $customer = $intake->matchCustomer(email: $from);

        $intake->recordInbound(
            channel: TicketChannel::Email,
            messageChannel: MessageChannel::Email,
            customer: $customer,
            body: $body,
            threadRef: $this->threadRef($from, $subject),
            externalMessageId: $messageId,
            subject: $subject,
        );

        $event->markProcessed();
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
