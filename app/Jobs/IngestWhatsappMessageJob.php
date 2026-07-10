<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Automation\ApprovalGate;
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

    public function handle(TicketIntake $intake): void
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

        // Owner approval commands ("אשר 12" / "דחה 12") are routed to the
        // approval gate instead of opening a ticket; the decision result is
        // sent straight back to the owner.
        $gate = app(ApprovalGate::class);

        if (($reply = $gate->handleOwnerMessage($chatId, $body)) !== null) {
            try {
                app(WahaClient::class)->sendMessage($chatId, $reply);
            } catch (\Throwable) {
                // The decision is already recorded; the panel shows the outcome.
            }
            $event->markProcessed();

            return;
        }

        // The approvals chat (owner's number or a team group) is an operations
        // channel — regular chatter there must never open customer tickets.
        if ($gate->ownerChatId() !== null && $chatId === $gate->ownerChatId()) {
            $event->markProcessed();

            return;
        }

        $customer = $this->matchCustomer($intake, $chatId);

        $intake->recordInbound(
            channel: TicketChannel::Whatsapp,
            messageChannel: MessageChannel::Whatsapp,
            customer: $customer,
            body: $body,
            threadRef: $chatId,
            externalMessageId: $messageId,
            attachments: ! empty($payload['hasMedia']) ? ['media' => $payload['media'] ?? true] : null,
        );

        $event->markProcessed();
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
