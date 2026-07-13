<?php

namespace App\Services\Support;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\ClassifyTicketJob;
use App\Jobs\NotifyTeamJob;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Str;

/**
 * Single entry point for turning an inbound contact (WhatsApp, email, web form)
 * into a ticket message. Centralizes customer matching, ticket find-or-create,
 * message dedupe and reopen-on-reply so every channel behaves identically.
 */
class TicketIntake
{
    /**
     * Match an inbound contact to a customer by any identifier we hold.
     * WhatsApp JID is matched exactly; phone/email fall back to a lookup.
     */
    public function matchCustomer(?string $email = null, ?string $phone = null, ?string $whatsappJid = null): ?Customer
    {
        return Customer::query()
            ->when($whatsappJid, fn ($q) => $q->orWhere('whatsapp_jid', $whatsappJid))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when(! $whatsappJid && ! $email && ! $phone, fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }

    /**
     * Record an inbound message.
     *
     * When $threadRef is given, the message is appended to the open ticket that
     * carries that reference (a continuing conversation); otherwise a fresh
     * ticket is opened. Redelivered messages are deduped on $externalMessageId.
     */
    public function recordInbound(
        TicketChannel $channel,
        MessageChannel $messageChannel,
        ?Customer $customer,
        string $body,
        ?string $threadRef = null,
        ?string $externalMessageId = null,
        ?string $subject = null,
        ?array $attachments = null,
        ?string $contactName = null,
        ?string $contactHandle = null,
    ): TicketMessage {
        $ticket = $this->findOrCreateTicket($channel, $customer, $threadRef, $subject, $body, $contactName, $contactHandle);

        $message = $ticket->messages()->firstOrCreate(
            ['external_message_id' => $externalMessageId],
            [
                'direction' => MessageDirection::Inbound,
                'channel' => $messageChannel,
                'body' => $body !== '' ? $body : '[ללא תוכן טקסט]',
                'author' => MessageAuthor::Customer,
                'attachments' => $attachments,
            ],
        );

        if ($message->wasRecentlyCreated) {
            // A customer reply puts the ball back with us: any non-open ticket
            // (waiting-for-customer, on-hold, resolved, closed) returns to open.
            if ($ticket->status !== TicketStatus::Open) {
                $ticket->update(['status' => TicketStatus::Open]);
            }

            if ($ticket->wasRecentlyCreated) {
                // A brand-new ticket gets an immediate personal acknowledgement
                // on its originating channel ("received, we're on it") —
                // template-driven and operator-editable; the job no-ops if
                // disabled/unreachable. Manual (team-opened) tickets are
                // internal, so acknowledging the customer would be nonsense.
                if ($channel !== TicketChannel::Manual) {
                    SendTicketNotificationJob::dispatch($ticket->id, 'ticket.received');
                }

                // Always alert the team (WhatsApp approvals number/group + email)
                // about a new ticket — independent of the AI layer.
                NotifyTeamJob::dispatch($ticket->id, 'new_ticket');
            } else {
                // A customer reply on an existing ticket — alert the team too.
                NotifyTeamJob::dispatch($ticket->id, 'new_reply', $message->id);
            }

            // Kick off optional Tier-1 AI (classification → draft reply). The
            // job is a no-op when the AI layer is disabled, and never sends
            // anything to the customer — drafts await agent approval.
            ClassifyTicketJob::dispatch($ticket->id);
        }

        return $message;
    }

    protected function findOrCreateTicket(
        TicketChannel $channel,
        ?Customer $customer,
        ?string $threadRef,
        ?string $subject,
        string $body,
        ?string $contactName = null,
        ?string $contactHandle = null,
    ): Ticket {
        if ($threadRef !== null) {
            $open = Ticket::where('external_thread_ref', $threadRef)
                ->where('status', '!=', TicketStatus::Closed)
                ->latest('id')
                ->first();

            if ($open) {
                return $open;
            }
        }

        return Ticket::create([
            'customer_id' => $customer?->id,
            // Remember who an unidentified enquiry is from so it still shows a
            // name/handle rather than "פונה לא מזוהה".
            'contact_name' => $customer ? null : ($contactName ?: null),
            'contact_handle' => $customer ? null : ($contactHandle ?: null),
            'channel' => $channel,
            'subject' => $this->buildSubject($customer, $subject, $body),
            'status' => TicketStatus::Open,
            'external_thread_ref' => $threadRef,
        ]);
    }

    protected function buildSubject(?Customer $customer, ?string $subject, string $body): string
    {
        if (filled($subject)) {
            return Str::limit($subject, 120);
        }

        if (! $customer) {
            return 'פנייה לא מזוהה';
        }

        return Str::limit($body !== '' ? $body : 'פנייה חדשה', 80);
    }
}
