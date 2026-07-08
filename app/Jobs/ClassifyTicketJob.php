<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tier-1 AI classification (Stage 5, optional). Suggests a priority and a short
 * category label for a fresh ticket. Advisory only — it never sends anything to
 * the customer; a human still owns every outbound message.
 */
class ClassifyTicketJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $ticketId) {}

    public function handle(ClaudeClient $claude): void
    {
        if (! $claude->isEnabled()) {
            return;
        }

        $ticket = Ticket::with(['messages' => fn ($q) => $q->latest('created_at')->limit(5)])
            ->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $conversation = $ticket->messages
            ->reverse()
            ->map(fn ($m) => "[{$m->author->value}] {$m->body}")
            ->implode("\n");

        $result = $claude->structured(
            system: 'אתה מסווג פניות תמיכה של חברת אחסון ותחזוקת אתרים. החזר סיווג תמציתי בעברית.',
            prompt: "נושא: {$ticket->subject}\n\nשיחה:\n{$conversation}",
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['low', 'normal', 'high', 'urgent'],
                    ],
                    'intent' => [
                        'type' => 'string',
                        'enum' => ['site_down', 'billing', 'invoice', 'account', 'card_update', 'technical', 'other'],
                    ],
                    'category' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['priority', 'intent', 'category', 'summary'],
            ],
        );

        if (! $result) {
            return;
        }

        $priority = TicketPriority::tryFrom($result['priority'] ?? '') ?? $ticket->priority;

        $ticket->update(['priority' => $priority]);

        // Record the classification as an internal note so agents see the AI's
        // reasoning without it ever reaching the customer.
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::InternalNote,
            'body' => sprintf(
                "🤖 סיווג AI\nעדיפות: %s\nכוונה: %s\nקטגוריה: %s\nתקציר: %s",
                $priority->value,
                $result['intent'] ?? '-',
                $result['category'] ?? '-',
                $result['summary'] ?? '-',
            ),
            'author' => MessageAuthor::Ai,
        ]);

        // Chain a draft reply for the agent to review.
        DraftReplyJob::dispatch($ticket->id);
    }
}
