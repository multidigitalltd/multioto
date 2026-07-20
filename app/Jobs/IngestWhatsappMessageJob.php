<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Automation\ApprovalGate;
use App\Services\Automation\ManagementCommands;
use App\Services\Support\AttachmentStore;
use App\Services\Support\TicketIntake;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Turn an inbound WAHA message into a ticket message: match the sender to a
 * customer by WhatsApp id / phone, then hand off to TicketIntake which opens or
 * continues the conversation. Unmatched senders get an "unidentified" ticket.
 */
class IngestWhatsappMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $webhookEventId) {}

    public function handle(TicketIntake $intake, WahaClient $waha, AttachmentStore $attachments): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = $event->payload['payload'] ?? $event->payload;

        $chatId = (string) ($payload['from'] ?? '');
        $body = trim((string) ($payload['body'] ?? ''));
        $messageId = $payload['id'] ?? null;

        // A shared contact card (vCard) carries no text body and no media, so it
        // would otherwise be dropped below. Render it as readable text (name +
        // phone) so a contact the customer sends actually lands in the thread.
        if ($body === '' || Str::contains($body, 'BEGIN:VCARD')) {
            $body = $this->contactSummary($payload) ?? $body;
        }

        if ($chatId === '' || ($body === '' && empty($payload['hasMedia']))) {
            $event->markProcessed();

            return;
        }

        // The management group is the team's operations channel: approvals plus
        // full ticket management (open / list / close) run from there, and it
        // NEVER opens a customer ticket from ordinary chatter.
        $gate = app(ApprovalGate::class);
        $managementChat = $gate->ownerChatId();

        if ($managementChat !== null && $chatId === $managementChat) {
            $reply = app(ManagementCommands::class)->handle($chatId, $body, $messageId ? (string) $messageId : null);

            if ($reply !== null) {
                try {
                    app(WahaClient::class)->sendMessage($chatId, $reply);
                } catch (\Throwable) {
                    // The action is already recorded; the panel shows the outcome.
                }
            }

            $event->markProcessed();

            return;
        }

        // Listen only to direct customer chats. Ignore groups, status/story
        // broadcasts and channels (newsletters) — the only group we act on is the
        // management group handled above.
        if ($this->isGroupOrBroadcast($chatId)) {
            $event->markProcessed();

            return;
        }

        $customer = $this->matchCustomer($intake, $chatId);

        // Keep the sender's identity for unidentified enquiries: WhatsApp
        // pushname (when present) + their phone number.
        $pushName = trim((string) ($payload['notifyName'] ?? ($payload['_data']['notifyName'] ?? '')));

        $message = $intake->recordInbound(
            channel: TicketChannel::Whatsapp,
            messageChannel: MessageChannel::Whatsapp,
            customer: $customer,
            body: $body,
            threadRef: $chatId,
            externalMessageId: $messageId,
            contactName: $pushName ?: null,
            contactHandle: '+'.Str::before($chatId, '@'),
            // Once a WhatsApp ticket is done (handled OR closed), the next
            // message from the customer opens a FRESH ticket instead of reviving
            // the old thread — a new contact is treated as a new enquiry.
            terminalStatuses: [TicketStatus::Closed, TicketStatus::Resolved],
        );

        // Download and store the media the customer sent (image/file), then keep
        // its metadata on the message. First ingest only — recordInbound is
        // idempotent per message id.
        if ($message->wasRecentlyCreated && ! empty($payload['hasMedia'])) {
            $stored = $this->storeMedia($waha, $attachments, $message->ticket_id, $payload);

            if ($stored !== null) {
                $message->update(['attachments' => [$stored]]);
            }
        }

        $event->markProcessed();
    }

    /**
     * Fetch the media referenced by a WAHA message and store it. WAHA exposes it
     * as a URL on its own server (media.url) that needs the API key; shapes vary
     * across versions, so we read defensively. Returns null (message kept
     * without the file) on anything unexpected.
     *
     * @param  array<string, mixed>  $payload
     * @return array{name: string, mime: string, size: int, path: string, disk: string}|null
     */
    protected function storeMedia(WahaClient $waha, AttachmentStore $store, int $ticketId, array $payload): ?array
    {
        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $url = (string) ($media['url'] ?? $payload['mediaUrl'] ?? '');

        if ($url === '') {
            return null;
        }

        $contents = $waha->downloadMedia($url);

        if ($contents === null) {
            return null;
        }

        $filename = (string) ($media['filename'] ?? $payload['filename'] ?? 'whatsapp-media');
        $mime = (string) ($media['mimetype'] ?? $payload['mimetype'] ?? '') ?: null;

        return $store->store($ticketId, $filename, $contents, $mime);
    }

    /**
     * Turn a WhatsApp contact-card (vCard) message into a readable line per
     * contact — "📇 איש קשר שהתקבל:\n<name> — <phone>" — or null when the payload
     * holds no vCard. WAHA exposes the card differently across engines, so we
     * read defensively and never throw.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function contactSummary(array $payload): ?string
    {
        $lines = [];

        foreach ($this->collectVcards($payload) as $card) {
            $name = $this->vcardValue($card, 'FN') ?? $this->vcardValue($card, 'N');
            $phone = $this->vcardPhone($card);
            $label = trim(($name ?? 'איש קשר').($phone !== null ? ' — '.$phone : ''));

            if ($label !== '') {
                $lines[] = $label;
            }
        }

        if ($lines === []) {
            return null;
        }

        $heading = count($lines) > 1 ? '📇 אנשי קשר שהתקבלו:' : '📇 איש קשר שהתקבל:';

        return $heading."\n".implode("\n", $lines);
    }

    /**
     * Gather raw vCard strings from the shapes WAHA uses (top-level `vcard`, a
     * `vCards` list, the same under `_data`, or a raw card inlined in the body).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function collectVcards(array $payload): array
    {
        $candidates = [];

        foreach ([$payload, is_array($payload['_data'] ?? null) ? $payload['_data'] : []] as $source) {
            if (is_string($source['vcard'] ?? null)) {
                $candidates[] = $source['vcard'];
            }

            foreach ((array) ($source['vCards'] ?? []) as $vcard) {
                if (is_string($vcard)) {
                    $candidates[] = $vcard;
                }
            }
        }

        if (is_string($payload['body'] ?? null)) {
            $candidates[] = $payload['body'];
        }

        return array_values(array_filter(
            $candidates,
            fn (string $card): bool => Str::contains($card, 'BEGIN:VCARD'),
        ));
    }

    /** Read a single vCard property value (e.g. FN), tolerating property params. */
    protected function vcardValue(string $card, string $field): ?string
    {
        if (preg_match('/^'.preg_quote($field, '/').'[^:\r\n]*:(.+)$/mi', $card, $m) !== 1) {
            return null;
        }

        // The structured N field is ";"-separated (Last;First;…) — flatten it.
        $value = trim(str_replace(';', ' ', trim($m[1])));

        return $value !== '' ? $value : null;
    }

    /** The contact's phone — prefer the clean WhatsApp id (waid=…) over the raw TEL. */
    protected function vcardPhone(string $card): ?string
    {
        if (preg_match('/waid=(\d+)/i', $card, $m) === 1) {
            return '+'.$m[1];
        }

        if (preg_match('/^TEL[^:\r\n]*:(.+)$/mi', $card, $m) === 1) {
            $phone = trim($m[1]);

            return $phone !== '' ? $phone : null;
        }

        return null;
    }

    /**
     * Whether a WhatsApp chat id is a group, a status/story broadcast or a
     * channel (newsletter) — anything that is not a one-to-one customer chat
     * (which uses the "@c.us" suffix). We never open tickets from these.
     */
    protected function isGroupOrBroadcast(string $chatId): bool
    {
        return Str::endsWith($chatId, ['@g.us', '@newsletter', '@broadcast'])
            || $chatId === 'status@broadcast';
    }

    /**
     * Match by JID, then by phone — and remember the JID on the customer so
     * future messages match exactly.
     */
    protected function matchCustomer(TicketIntake $intake, string $chatId): ?Customer
    {
        $phone = '+'.Str::before($chatId, '@');
        $customer = $intake->matchCustomer(phone: $phone, whatsappJid: $chatId);

        if ($customer && $customer->whatsapp_jid === null) {
            $customer->update(['whatsapp_jid' => $chatId]);
        }

        return $customer;
    }
}
