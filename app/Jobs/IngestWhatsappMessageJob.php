<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
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
            $reply = app(ManagementCommands::class)->handle($chatId, $body);

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

        $message = $intake->recordInbound(
            channel: TicketChannel::Whatsapp,
            messageChannel: MessageChannel::Whatsapp,
            customer: $customer,
            body: $body,
            threadRef: $chatId,
            externalMessageId: $messageId,
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
