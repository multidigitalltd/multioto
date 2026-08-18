<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Support\AgentReply;
use App\Services\Support\AttachmentStore;
use App\Services\Support\TicketIntake;
use App\Support\EmailBody;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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

    /**
     * Copied addresses one message may add to a ticket.
     *
     * A message sent to a distribution list would otherwise turn every future
     * reply into a broadcast nobody chose to send.
     */
    private const MAX_COPIED_WATCHERS = 10;

    public array $backoff = [30, 120];

    public function __construct(public int $webhookEventId) {}

    public function handle(TicketIntake $intake, AttachmentStore $attachments, AgentReply $agentReply): void
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
        $html = $payload['HtmlBody'] ?? $payload['html'] ?? null;
        $body = EmailBody::toText(
            $payload['TextBody'] ?? $payload['text'] ?? $payload['StrippedTextReply'] ?? null,
            $html,
        );
        // Display-only sanitized rich rendering, so the conversation view keeps
        // the email's formatting instead of a flattened run of text.
        $bodyHtml = EmailBody::toSafeHtml($html);
        $messageId = $payload['MessageID'] ?? $payload['message_id'] ?? null;

        if ($from === '') {
            $event->markProcessed();

            return;
        }

        // An agent answering the ticket by email (they replied to the alert):
        // route it back out to the customer instead of recording it as inbound.
        // Authorisation needs BOTH the signed token that only the team's alert
        // email carries (unforgeable — HMAC on the app secret) AND a known team
        // From. A spoofed From without the token falls through to customer intake.
        $threadTicketId = Ticket::idFromSubject($subject);
        if ($threadTicketId !== null
            && Ticket::agentReplyTokenMatches($threadTicketId, $subject)
            && User::query()->whereRaw('lower(email) = ?', [$from])->exists()) {
            $ticket = Ticket::find($threadTicketId);

            if ($ticket) {
                // Prefer the stripped reply (the agent's new text only, without
                // the quoted thread) so the customer doesn't get the history back.
                $agentBody = EmailBody::toText(
                    $payload['StrippedTextReply'] ?? $payload['TextBody'] ?? $payload['text'] ?? null,
                    $html,
                );

                if ($agentBody !== '') {
                    $agentReply->send($ticket, $agentBody);
                    $event->markProcessed();

                    return;
                }
            }
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
            bodyHtml: $bodyHtml,
            threadRef: $this->threadRef($from, $subject),
            externalMessageId: $messageId,
            subject: $subject,
            contactName: $fromName ?: null,
            contactHandle: $from,
            // A reply keeps our [#id] tag in the subject → thread onto that ticket.
            threadTicketId: $threadTicketId,
        );

        // Store any attachments (Postmark sends them base64-encoded inline) and
        // record their metadata on the just-created message. Only on first
        // ingest — recordInbound is idempotent per external id.
        // Null means the message was an opt-out request and never became
        // support correspondence — there is nothing to attach files to.
        if ($message?->wasRecentlyCreated) {
            $stored = $this->storeAttachments($attachments, $message->ticket_id, $payload['Attachments'] ?? $payload['attachments'] ?? []);

            if ($stored !== []) {
                $message->update(['attachments' => $stored]);
            }
        }

        // Whoever the sender copied is part of this conversation. Outside the
        // wasRecentlyCreated guard on purpose: somebody copied into the middle
        // of a thread is precisely the person we must not keep missing.
        if ($message?->ticket !== null) {
            $this->registerCopiedWatchers($message->ticket, $payload, $from);
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
     * Record everyone the sender copied as a watcher on the ticket.
     *
     * When somebody writes to support and copies their accountant, their
     * partner, or a colleague, that person is part of the conversation from its
     * first line. Until now we read only the From header, so the copied person
     * existed nowhere: the team could not see they were on the thread, and every
     * reply we sent left them out. From their side the correspondence simply
     * stopped — and the sender, who put them there on purpose, had no way to
     * know we had dropped them.
     *
     * Run on EVERY inbound message and not only the first, because somebody
     * copied into the middle of a thread ("adding our lawyer") is exactly the
     * case where being silently ignored matters most.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function registerCopiedWatchers(Ticket $ticket, array $payload, string $from): void
    {
        $skip = $this->addressesWeNeverCopy($from);
        $added = 0;

        foreach ($this->copiedAddresses($payload) as $email => $name) {
            if (in_array($email, $skip, true) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            // A cap, because a message copied to a distribution list would
            // otherwise turn every future reply into a broadcast we never chose
            // to send. What is dropped is logged rather than silently ignored.
            if ($added >= self::MAX_COPIED_WATCHERS) {
                Log::info('IngestEmailMessageJob: copied addresses beyond the cap were not added', [
                    'ticket' => $ticket->id,
                    'cap' => self::MAX_COPIED_WATCHERS,
                ]);

                break;
            }

            // firstOrCreate: a thread where the same people are copied on every
            // reply must not accumulate duplicates, and an address the team
            // added by hand keeps the attribution it already had.
            $ticket->watchers()->firstOrCreate(
                ['email' => $email],
                ['name' => $name !== '' ? $name : null, 'added_by' => 'הפונה (מכותב במייל)'],
            );

            $added++;
        }
    }

    /**
     * The addresses copied on this message, as email => display name.
     *
     * Both shapes the provider sends: `CcFull` (structured, with names) and the
     * plain `Cc` header. Bcc is deliberately absent — we only ever see it when
     * it was addressed to us, and treating a blind copy as a visible
     * participant would expose it to everyone else on the thread.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function copiedAddresses(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['CcFull'] ?? []) as $entry) {
            $email = $this->normalizeEmail((string) ($entry['Email'] ?? ''));

            if ($email !== '') {
                $out[$email] = trim((string) ($entry['Name'] ?? ''));
            }
        }

        foreach (explode(',', (string) ($payload['Cc'] ?? $payload['cc'] ?? '')) as $part) {
            $email = $this->normalizeEmail($part);

            if ($email !== '' && ! isset($out[$email])) {
                $out[$email] = '';
            }
        }

        return $out;
    }

    /**
     * Addresses that must never become watchers.
     *
     * Our own mailbox, because copying ourselves on our own replies is a loop.
     * The team's own addresses, because they already receive the internal alert
     * and would get every reply twice. And the sender, who is the recipient of
     * the reply, not a copy of it.
     *
     * @return list<string>
     */
    protected function addressesWeNeverCopy(string $from): array
    {
        $ours = [
            $from,
            Str::lower(trim((string) config('mail.from.address'))),
            Str::lower(trim((string) config('billing.notifications.reply_to', ''))),
        ];

        $team = User::query()->pluck('email')
            ->map(fn (?string $email): string => Str::lower(trim((string) $email)))
            ->all();

        return array_values(array_filter(array_unique([...$ours, ...$team])));
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
