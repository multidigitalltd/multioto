<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Notifications\TeamNotifier;
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

    public function handle(ClaudeClient $claude, TeamNotifier $team): void
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
            system: 'אתה מסווג פניות תמיכה של חברת אחסון ותחזוקת אתרים. החזר סיווג תמציתי בעברית, כולל תחושת הלקוח (sentiment).',
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
                    'sentiment' => [
                        'type' => 'string',
                        'enum' => ['positive', 'neutral', 'negative', 'angry'],
                    ],
                ],
                'required' => ['priority', 'intent', 'category', 'summary', 'sentiment'],
            ],
        );

        if (! $result) {
            return;
        }

        $sentiment = (string) ($result['sentiment'] ?? 'neutral');
        $priority = TicketPriority::tryFrom($result['priority'] ?? '') ?? $ticket->priority;
        // Never let an upset customer sit in a low queue: floor the priority by
        // sentiment (angry ⇒ urgent, negative ⇒ high), taking the higher of the
        // AI's own priority and this floor.
        $priority = $this->escalate($priority, $sentiment);

        $ticket->update([
            'priority' => $priority,
            'ai_summary' => $result['summary'] ?? null,
            'ai_topic' => $result['category'] ?? ($result['intent'] ?? null),
            'ai_sentiment' => $sentiment,
        ]);

        // Record the classification as an internal note so agents see the AI's
        // reasoning without it ever reaching the customer.
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::InternalNote,
            'body' => sprintf(
                "🤖 סיווג AI\nעדיפות: %s\nתחושת הלקוח: %s\nכוונה: %s\nקטגוריה: %s\nתקציר: %s",
                $priority->value,
                $this->sentimentLabel($sentiment),
                $result['intent'] ?? '-',
                $result['category'] ?? '-',
                $result['summary'] ?? '-',
            ),
            'author' => MessageAuthor::Ai,
        ]);

        // An angry customer is escalated to the team immediately.
        if ($sentiment === 'angry') {
            $team->alert(
                "😠 לקוח כועס — פנייה #{$ticket->id}",
                "זוהתה תחושת כעס בפנייה מ{$ticket->senderName()}.\n"
                    ."נושא: {$ticket->subject}\n"
                    .'תקציר: '.($result['summary'] ?? '-'),
                rtrim((string) config('app.url'), '/')."/admin/tickets/{$ticket->id}",
            );
        }

        // Chain a draft reply for the agent to review.
        DraftReplyJob::dispatch($ticket->id);
    }

    /** Raise the priority to a sentiment-driven floor (keeps the higher of two). */
    private function escalate(TicketPriority $priority, string $sentiment): TicketPriority
    {
        $rank = ['low' => 0, 'normal' => 1, 'high' => 2, 'urgent' => 3];
        $floor = match ($sentiment) {
            'angry' => 'urgent',
            'negative' => 'high',
            default => 'low',
        };

        return ($rank[$priority->value] ?? 1) >= $rank[$floor]
            ? $priority
            : TicketPriority::from($floor);
    }

    /** Hebrew label for the sentiment note. */
    private function sentimentLabel(string $sentiment): string
    {
        return match ($sentiment) {
            'positive' => 'חיובית 🙂',
            'negative' => 'שלילית 🙁',
            'angry' => 'כועס 😠',
            default => 'ניטרלית 😐',
        };
    }
}
