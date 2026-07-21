<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\SupportToolkit;
use App\Services\Automation\ApprovalGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

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

        // Decide by the last message that actually went to/from the customer,
        // ignoring internal notes (the AI classification note, an earlier draft,
        // an agent's private note). Otherwise the classification note that
        // ClassifyTicketJob writes just before dispatching us — or any internal
        // note on an already-open ticket — would look like "the last message
        // isn't from the customer" and suppress every draft. Draft only when the
        // latest customer-facing message is still an unanswered inbound one.
        $latest = $ticket->messages->firstWhere(fn ($m) => $m->channel !== MessageChannel::InternalNote);
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

        // Knowledge base: how the team actually resolved past enquiries on the
        // same AI topic. Grounds the draft in solutions that already worked.
        $pastSolutions = $this->pastSolutions($ticket);

        // Real, read-only facts about this customer (account, billing, uptime,
        // last invoice + a card-update link) so the draft answers concretely.
        $facts = $ticket->customer
            ? $toolkit->factsFor($ticket->customer)
            : 'הפנייה אינה מקושרת ללקוח מזוהה.';

        $result = $claude->structured(
            system: $this->systemPrompt(),
            prompt: "לקוח: {$ticket->customer?->name}\nנושא: {$ticket->subject}\n\nנתוני הלקוח:\n{$facts}\n\nשיחה:\n{$conversation}\n\nתבניות מענה זמינות:\n{$cannedContext}".$pastSolutions,
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
     * Knowledge base retrieval: past enquiries on the SAME AI topic that were
     * resolved/closed, each summarised by its final sent agent reply. This turns
     * the team's accumulated answers into grounding for the draft — the AI reuses
     * what already worked instead of inventing a fresh answer every time.
     *
     * Returns a prompt fragment (leading newlines included) or '' when the ticket
     * has no topic yet or no comparable resolved ticket exists.
     */
    protected function pastSolutions(Ticket $ticket): string
    {
        $topic = trim((string) $ticket->ai_topic);
        if ($topic === '') {
            return '';
        }

        // Only a real, sent agent reply (never an internal note or an AI-authored
        // draft) counts as a solution.
        $hasAgentReply = fn ($q) => $q->where('channel', '!=', MessageChannel::InternalNote)
            ->where('author', MessageAuthor::Agent);

        $solved = Ticket::query()
            ->where('ai_topic', $topic)
            ->whereKeyNot($ticket->id)
            ->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
            // Constrain to tickets that actually carry a usable agent reply BEFORE
            // taking the newest three — otherwise reply-less closes could fill all
            // three slots and leave the prompt ungrounded.
            ->whereHas('messages', $hasAgentReply)
            // resolved_at is only set on some terminal transitions; fall back to
            // updated_at (always bumped on the status change) so "latest" is real.
            ->orderByRaw('COALESCE(resolved_at, updated_at) DESC')
            ->limit(3)
            ->with(['messages' => fn ($q) => $hasAgentReply($q)
                ->latest('created_at')
                ->limit(1)])
            ->get();

        $examples = $solved
            ->map(function (Ticket $t): ?string {
                $reply = $t->messages->first()?->body;

                return blank($reply)
                    ? null
                    // Strip customer-specific secrets/PII (signed links, emails)
                    // from BOTH the subject and the reply — inbound email subjects
                    // are stored verbatim, so one customer's data never grounds
                    // another's draft.
                    : '• '.$this->redact((string) $t->subject)."\n  פתרון: ".Str::limit($this->redact((string) $reply), 500);
            })
            ->filter()
            ->implode("\n\n");

        return $examples === ''
            ? ''
            : "\n\nפתרונות מפניות דומות שנסגרו (מאגר ידע — השתמש כרפרנס, אל תעתיק מילה במילה):\n{$examples}";
    }

    /**
     * Redact customer-specific secrets and PII from a past reply before it grounds
     * a DIFFERENT customer's draft. Links are the real hazard: a signed
     * card-update / payment URL copied verbatim would hand one customer a still-valid
     * privileged link belonging to another. Email addresses are scrubbed as PII.
     * Trims whitespace so the snippet stays clean.
     */
    protected function redact(string $reply): string
    {
        $reply = preg_replace('#https?://\S+#i', '[קישור הוסר]', $reply);
        $reply = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', '[אימייל הוסר]', (string) $reply);

        return trim((string) $reply);
    }

    /**
     * The agent's system prompt: the operator-editable persona + guardrails
     * (from settings), plus a non-negotiable safety line appended in code.
     */
    protected function systemPrompt(): string
    {
        $style = trim((string) config('billing.ai.style_summary'));

        return implode("\n", array_filter([
            trim((string) config('billing.ai.persona')),
            '',
            'כללים מחייבים:',
            trim((string) config('billing.ai.rules')),
            // Style learned from the team's past replies, so drafts match how
            // they actually write.
            $style !== '' ? "\nסגנון הצוות (נלמד מתשובות קודמות) — נסח בהתאם:\n{$style}" : null,
            '',
            'התשובה נשמרת כטיוטה פנימית ותעבור אישור אנושי לפני שליחה — אל תתחייב בשם החברה.',
        ], fn ($line) => $line !== null));
    }
}
