<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\SupportToolkit;
use App\Services\Automation\ApprovalGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Draft a suggested reply for a ticket (Stage 5, optional).
 *
 * HUMAN-IN-THE-LOOP BY DESIGN: the draft is stored as an internal note, never
 * as an outbound message. SendTicketReplyJob explicitly skips internal notes,
 * so nothing is delivered to the customer until an agent reviews the draft and
 * chooses to send it. The AI never speaks to a customer on its own.
 */
class DraftReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $ticketId) {}

    public function handle(ClaudeClient $claude, SupportToolkit $toolkit): void
    {
        if (! $claude->isEnabled()) {
            return;
        }

        $ticket = Ticket::with([
            'customer',
            'messages' => fn ($q) => $q->latest('created_at')->limit(8),
        ])->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        // Nothing to answer yet if the last message isn't from the customer.
        $latest = $ticket->messages->first();
        if (! $latest || $latest->direction !== MessageDirection::Inbound) {
            return;
        }

        $cannedContext = CannedResponse::query()
            ->limit(20)
            ->get(['title', 'body'])
            ->map(fn ($c) => "- {$c->title}: {$c->body}")
            ->implode("\n");

        $conversation = $ticket->messages
            ->reverse()
            ->map(fn ($m) => "[{$m->author->value}] {$m->body}")
            ->implode("\n");

        // Real, read-only facts about this customer (account, billing, uptime,
        // last invoice + a card-update link) so the draft answers concretely.
        $facts = $ticket->customer
            ? $toolkit->factsFor($ticket->customer)
            : 'הפנייה אינה מקושרת ללקוח מזוהה.';

        $result = $claude->structured(
            system: $this->systemPrompt(),
            prompt: "לקוח: {$ticket->customer?->name}\nנושא: {$ticket->subject}\n\nנתוני הלקוח:\n{$facts}\n\nשיחה:\n{$conversation}\n\nתבניות מענה זמינות:\n{$cannedContext}",
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'reply' => ['type' => 'string'],
                    'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                ],
                'required' => ['reply', 'confidence'],
            ],
        );

        if (! $result || blank($result['reply'] ?? null)) {
            return;
        }

        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::InternalNote,
            'body' => sprintf(
                "🤖 טיוטת תשובה (ביטחון: %s) — לאישור לפני שליחה:\n\n%s",
                $result['confidence'] ?? '-',
                $result['reply'],
            ),
            'author' => MessageAuthor::Ai,
        ]);

        // Route the draft through the approval gate: the owner gets the full
        // proposed reply on WhatsApp and answers "אשר <id>" to send it to the
        // customer (or approves from the panel). Still human-in-the-loop —
        // only the approval channel got faster.
        app(ApprovalGate::class)->propose(
            type: 'ticket_reply',
            summary: sprintf(
                "תשובה ללקוח %s בפנייה #%d (%s):\n\n%s",
                $ticket->customer?->name ?? 'לא מזוהה',
                $ticket->id,
                $ticket->subject,
                $result['reply'],
            ),
            payload: ['reply' => $result['reply'], 'confidence' => $result['confidence'] ?? null],
            customerId: $ticket->customer_id,
            ticketId: $ticket->id,
        );
    }

    /**
     * The agent's system prompt: the operator-editable persona + guardrails
     * (from settings), plus a non-negotiable safety line appended in code.
     */
    protected function systemPrompt(): string
    {
        return implode("\n", array_filter([
            trim((string) config('billing.ai.persona')),
            '',
            'כללים מחייבים:',
            trim((string) config('billing.ai.rules')),
            '',
            'התשובה נשמרת כטיוטה פנימית ותעבור אישור אנושי לפני שליחה — אל תתחייב בשם החברה.',
        ], fn ($line) => $line !== null));
    }
}
