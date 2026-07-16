<?php

namespace App\Services\Support;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\ClassifyTicketJob;
use App\Jobs\InvestigateTicketJob;
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
        ?int $threadTicketId = null,
        ?string $bodyHtml = null,
        array $terminalStatuses = [TicketStatus::Closed],
    ): TicketMessage {
        $ticket = $this->findOrCreateTicket($channel, $customer, $threadRef, $subject, $body, $contactName, $contactHandle, $threadTicketId, $terminalStatuses);

        $message = $ticket->messages()->firstOrCreate(
            ['external_message_id' => $externalMessageId],
            [
                'direction' => MessageDirection::Inbound,
                'channel' => $messageChannel,
                'body' => $body !== '' ? $body : '[ללא תוכן טקסט]',
                'body_html' => $bodyHtml,
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

                // Optionally let the site agent look at the customer's connected
                // site right away and post a "what to do" system note (off by
                // default — costs model tokens; toggled in the AI-agent settings).
                if (config('agent.auto_investigate_tickets')) {
                    InvestigateTicketJob::dispatch($ticket->id);
                }
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

    /**
     * @param  list<TicketStatus>  $terminalStatuses  statuses that end a
     *                                                conversation — a message
     *                                                arriving after one of them
     *                                                opens a NEW ticket rather
     *                                                than reviving the thread.
     */
    protected function findOrCreateTicket(
        TicketChannel $channel,
        ?Customer $customer,
        ?string $threadRef,
        ?string $subject,
        string $body,
        ?string $contactName = null,
        ?string $contactHandle = null,
        ?int $threadTicketId = null,
        array $terminalStatuses = [TicketStatus::Closed],
    ): Ticket {
        // An explicit ticket tag ([MD#id] in an email subject) threads onto that
        // exact ticket — even if closed (recordInbound reopens it) — regardless
        // of sender or how the ticket originated.
        if ($threadTicketId !== null && ($tagged = Ticket::find($threadTicketId)) !== null) {
            // Fill an unidentified ticket's empty sender fields from this reply,
            // so a manually-opened ticket stops showing as unidentified.
            if ($tagged->customer_id === null) {
                $fill = [];
                if (blank($tagged->contact_name) && filled($contactName)) {
                    $fill['contact_name'] = $contactName;
                }
                if (blank($tagged->contact_handle) && filled($contactHandle)) {
                    $fill['contact_handle'] = $contactHandle;
                }
                if ($fill !== []) {
                    $tagged->update($fill);
                }
            }

            return $tagged;
        }

        if ($threadRef !== null) {
            $open = Ticket::where('external_thread_ref', $threadRef)
                ->whereNotIn('status', $terminalStatuses)
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
