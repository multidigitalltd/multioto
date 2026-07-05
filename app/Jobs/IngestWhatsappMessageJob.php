<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Turn an inbound WAHA message into a ticket message: match the sender to a
 * customer by WhatsApp id / phone, append to their open WhatsApp ticket or
 * open a fresh one. Unmatched senders get an "unidentified" ticket for triage.
 */
class IngestWhatsappMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
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

        $customer = $this->matchCustomer($chatId);
        $ticket = $this->findOrCreateTicket($customer, $chatId, $body);

        // Unique external_message_id makes redelivered messages a no-op.
        $ticket->messages()->firstOrCreate(
            ['external_message_id' => $messageId],
            [
                'direction' => MessageDirection::Inbound,
                'channel' => MessageChannel::Whatsapp,
                'body' => $body !== '' ? $body : '[מדיה מצורפת]',
                'author' => MessageAuthor::Customer,
                'attachments' => ! empty($payload['hasMedia']) ? ['media' => $payload['media'] ?? true] : null,
            ],
        );

        // Reopen resolved conversations when the customer writes again.
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            $ticket->update(['status' => TicketStatus::Open]);
        }

        $event->markProcessed();
    }

    protected function matchCustomer(string $chatId): ?Customer
    {
        $customer = Customer::where('whatsapp_jid', $chatId)->first();

        if ($customer) {
            return $customer;
        }

        $phone = '+'.Str::before($chatId, '@');
        $customer = Customer::where('phone', $phone)->first();

        // Remember the JID for next time so future matching is exact.
        $customer?->update(['whatsapp_jid' => $chatId]);

        return $customer;
    }

    protected function findOrCreateTicket(?Customer $customer, string $chatId, string $body): Ticket
    {
        $open = Ticket::where('external_thread_ref', $chatId)
            ->whereNotIn('status', [TicketStatus::Closed])
            ->latest('id')
            ->first();

        return $open ?? Ticket::create([
            'customer_id' => $customer?->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => $customer
                ? Str::limit($body !== '' ? $body : 'פנייה חדשה בוואטסאפ', 80)
                : 'פנייה לא מזוהה בוואטסאפ',
            'status' => TicketStatus::Open,
            'external_thread_ref' => $chatId,
        ]);
    }
}
