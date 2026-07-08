<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\SupportToolkit;
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
            system: implode("\n", [
                'אתה נציג תמיכה של Multi Digital (אחסון ותחזוקת אתרים).',
                'נסח טיוטת תשובה מנומסת, קצרה וברורה בעברית לפנייה האחרונה של הלקוח.',
                'בסס את התשובה אך ורק על "נתוני הלקוח" שסופקו. אל תמציא פרטים שאינם שם.',
                'אם הלקוח דיווח שהאתר למטה — התייחס לסטטוס האתר מהנתונים.',
                'אם הלקוח שאל על חיוב/חשבונית — השתמש בנתוני החיוב/החשבונית.',
                'צרף את הקישור לעדכון כרטיס רק אם הלקוח ביקש לעדכן/להחליף אמצעי תשלום.',
                'התשובה תעבור אישור אנושי לפני שליחה.',
            ]),
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
    }
}
