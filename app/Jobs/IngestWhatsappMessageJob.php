<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Support\TicketIntake;
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
