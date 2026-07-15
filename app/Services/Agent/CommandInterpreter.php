<?php

namespace App\Services\Agent;

use App\Enums\AgentCommandOutcome;
use App\Enums\MessageChannel;
use App\Enums\TicketStatus;
use App\Jobs\InvestigateSiteJob;
use App\Models\AgentCommand;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Support\Str;

/**
 * The operator command console brain. It reads a free-text instruction in plain
 * Hebrew — "תענה למשה בכרטיס הפתוח שאנחנו על זה", "תנקה קאש באתר X" — understands
 * the intent, resolves the target (which ticket / which site), and routes it
 * through the SAME approval-gated machinery the rest of the system uses:
 *
 *   • a customer reply  → drafts it and files a ticket_reply proposal (the
 *                          operator approves — or edits & sends — from the
 *                          approvals inbox);
 *   • a site operation  → dispatches the read-only site agent toward that goal;
 *                          any concrete fix it finds is filed for approval.
 *
 * Nothing customer-facing and nothing on a site executes from here directly —
 * the console proposes, a human approves. That keeps the console safe today and
 * leaves the door open to fuller autonomy later (flip the approval step off).
 */
class CommandInterpreter
{
    public function __construct(
        private ClaudeClient $ai,
        private ApprovalGate $gate,
    ) {}

    /**
     * Interpret one instruction and act on it, recording the whole thing.
     *
     * $continues is a prior command that ended "needs clarification": the new
     * instruction is treated as the operator's answer and MERGED with the prior
     * text, so an unclear command is refined and continued instead of restarted.
     */
    public function run(string $instruction, ?int $userId = null, ?AgentCommand $continues = null): AgentCommand
    {
        $instruction = trim($instruction);

        // Carry the earlier attempt's full text so the clarification completes it
        // (and further clarifications keep accumulating context).
        $effective = $continues && $instruction !== ''
            ? trim($continues->instruction."\n\nהבהרה מהמפעיל: ".$instruction)
            : $instruction;

        $command = AgentCommand::create([
            'user_id' => $userId,
            'instruction' => $effective,
            'outcome' => AgentCommandOutcome::Unclear,
        ]);

        if ($instruction === '') {
            return $this->finish($command, AgentCommandOutcome::Unclear, 'לא הוזנה הוראה.');
        }

        if (! $this->ai->isEnabled()) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'סוכן ה-AI כבוי או ללא מפתח — הפעילו אותו בהגדרות "סוכן AI".');
        }

        try {
            $parsed = $this->classify($effective);
        } catch (\Throwable $e) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'הבנת ההוראה נכשלה: '.Str::limit($e->getMessage(), 160));
        }

        $command->intent = $parsed['intent'] ?? null;

        return match ($parsed['intent'] ?? 'unknown') {
            'ticket_reply' => $this->handleTicketReply($command, $parsed),
            'site_operation' => $this->handleSiteOperation($command, $parsed),
            'system_action' => $this->handleSystemAction($command, $parsed),
            default => $this->finish(
                $command,
                AgentCommandOutcome::Unclear,
                trim((string) ($parsed['clarification'] ?? '')) ?: 'לא הצלחתי להבין את ההוראה. נסחו מחדש — למשל "תענה ל<לקוח> בכרטיס הפתוח ש...", "תנקה קאש באתר <דומיין>", או "תשלח דרישת תשלום ל<לקוח> על <סכום>".',
            ),
        };
    }

    /**
     * Classify the instruction and pull out the target + detail. The model only
     * understands — it never acts here.
     *
     * @return array{intent: string, customer_name: ?string, ticket_id: ?int, site_domain: ?string, detail: string, clarification: ?string}
     */
    private function classify(string $instruction): array
    {
        $operations = SystemActionCatalog::promptList();

        $result = $this->ai->structured(
            system: <<<PROMPT
                אתה מנתב פקודות למסוף התפעול של Multi Digital. המפעיל כותב הוראה חופשית בעברית ואתה מסווג אותה בלבד — אינך מבצע דבר.

                סוגי כוונה (intent):
                - "ticket_reply": מענה/תשובה ללקוח בפנייה (למשל "תענה למשה שאנחנו על זה").
                - "site_operation": פעולה או בדיקה על אתר וורדפרס (למשל "תנקה קאש באתר X", "תבדוק למה האתר איטי").
                - "system_action": פעולה פנימית במערכת (גבייה, חשבוניות, משימות, אתרים/מנויים) — לא מענה ללקוח ולא בדיקת אתר.
                - "unknown": לא ברור או לא שייך.

                כשה-intent הוא system_action, קבע operation לאחת מהפעולות הבאות:
                {$operations}

                חוקים:
                - customer_name: שם הלקוח/איש הקשר אם הוזכר, אחרת null.
                - ticket_id: מספר פנייה אם צוין (מספר בלבד), אחרת null.
                - site_domain: דומיין האתר אם הוזכר (ללא https://), אחרת null.
                - amount_ils: סכום בשקלים אם הוזכר (מספר בלבד, למשל 300), אחרת null.
                - operation: מזהה הפעולה הפנימית (מהרשימה) כאשר intent=system_action, אחרת null.
                - detail: תמצית ברורה של מה לעשות (לגבי מענה: מה למסור ללקוח; לגבי משימה: כותרת המשימה; לגבי חיוב: על מה התשלום).
                - clarification: אם חסר מידע קריטי (למשל סכום/לקוח), כתוב בעברית בדיוק מה חסר, כשאלה קצרה שהמפעיל יוכל לענות עליה.
                PROMPT,
            prompt: $instruction,
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'intent' => ['type' => 'string', 'enum' => ['ticket_reply', 'site_operation', 'system_action', 'unknown']],
                    'operation' => ['type' => ['string', 'null']],
                    'customer_name' => ['type' => ['string', 'null']],
                    'ticket_id' => ['type' => ['integer', 'null']],
                    'site_domain' => ['type' => ['string', 'null']],
                    'amount_ils' => ['type' => ['number', 'null']],
                    'detail' => ['type' => 'string'],
                    'clarification' => ['type' => ['string', 'null']],
                ],
                'required' => ['intent', 'detail'],
            ],
        );

        if (! $result) {
            throw new \RuntimeException('ללא מענה מספק ה-AI.');
        }

        return $result;
    }

    /** Draft a customer reply from the instruction and file it for approval. */
    private function handleTicketReply(AgentCommand $command, array $parsed): AgentCommand
    {
        [$ticket, $error] = $this->resolveTicket(
            $parsed['ticket_id'] ?? null,
            $parsed['customer_name'] ?? null,
        );

        if (! $ticket) {
            return $this->finish($command, AgentCommandOutcome::Unclear, $error);
        }

        $command->ticket_id = $ticket->id;
        $command->customer_id = $ticket->customer_id;

        try {
            $reply = $this->draftReply($ticket, (string) ($parsed['detail'] ?? ''));
        } catch (\Throwable $e) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'ניסוח התשובה נכשל: '.Str::limit($e->getMessage(), 160));
        }

        if ($reply === '') {
            return $this->finish($command, AgentCommandOutcome::Failed, 'לא נוצרה תשובה — נסו לנסח את ההוראה מחדש.');
        }

        $action = $this->gate->propose(
            type: 'ticket_reply',
            summary: sprintf(
                "תשובה ללקוח %s בפנייה #%d (%s):\n\n%s",
                $ticket->customer?->name ?? 'לא מזוהה',
                $ticket->id,
                $ticket->subject,
                $reply,
            ),
            payload: ['reply' => $reply, 'source' => 'command_console'],
            customerId: $ticket->customer_id,
            ticketId: $ticket->id,
            proposedBy: 'console',
        );

        $command->pending_action_id = $action->id;

        return $this->finish(
            $command,
            AgentCommandOutcome::Proposed,
            "הוכנה תשובה לפנייה #{$ticket->id} והוגשה לאישור (#{$action->id}). ניתן לאשר, לערוך ולשלוח ממסך אישורי האוטומציה.",
        );
    }

    /** Send the read-only site agent toward the requested goal on the site. */
    private function handleSiteOperation(AgentCommand $command, array $parsed): AgentCommand
    {
        [$site, $error] = $this->resolveSite(
            $parsed['site_domain'] ?? null,
            $parsed['customer_name'] ?? null,
        );

        if (! $site) {
            return $this->finish($command, AgentCommandOutcome::Unclear, $error);
        }

        $command->site_id = $site->id;
        $command->customer_id = $site->customer_id;

        if (! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return $this->finish(
                $command,
                AgentCommandOutcome::Unclear,
                "האתר {$site->domain} אינו מחובר לסוכן (MCP כבוי או ללא כתובת). חברו אותו מכרטיס האתר ← \"חיבור לתוסף\".",
            );
        }

        $goal = trim((string) ($parsed['detail'] ?? '')) ?: $command->instruction;

        InvestigateSiteJob::dispatch($site->id, $goal);

        return $this->finish(
            $command,
            AgentCommandOutcome::Dispatched,
            "הסוכן בודק את {$site->domain} ({$goal}). הבדיקה רצה ברקע (קריאה בלבד); כל פעולה שתידרש תוגש לאישור במסך האישורים.",
        );
    }

    /**
     * Resolve an internal system operation into an approval-gate proposal. When a
     * required parameter is missing (which customer? how much?), it finishes as
     * "needs clarification" with a specific question — the operator's next reply
     * continues it (see run()'s $continues).
     */
    private function handleSystemAction(AgentCommand $command, array $parsed): AgentCommand
    {
        $operation = (string) ($parsed['operation'] ?? '');

        if (! SystemActionCatalog::has($operation)) {
            return $this->finish($command, AgentCommandOutcome::Unclear,
                trim((string) ($parsed['clarification'] ?? '')) ?: 'לא זיהיתי איזו פעולה פנימית לבצע. נסחו מחדש (למשל "תשלח דרישת תשלום ל<לקוח> על <סכום>").');
        }

        $detail = trim((string) ($parsed['detail'] ?? ''));
        $needsMoney = fn (): ?int => filled($parsed['amount_ils'] ?? null) && (float) $parsed['amount_ils'] > 0
            ? (int) round((float) $parsed['amount_ils'] * 100)
            : null;

        return match ($operation) {
            'open_task' => $this->proposeSystemAction($command, 'open_task',
                ['title' => $detail ?: 'משימה חדשה'] + $this->maybeCustomer($parsed),
                'משימה חדשה: '.($detail ?: 'ללא כותרת')),

            'send_payment_request' => $this->proposePaymentRequest($command, $parsed, $detail, $needsMoney()),

            'mark_collected' => $this->proposeForCustomer($command, 'mark_collected', $parsed,
                fn (Customer $c): string => "סימון תשלום בוצע + חשבונית עבור {$c->name}"),

            'suspend_site' => $this->proposeForSite($command, 'suspend_site', $parsed, 'השעיית אתר'),
            'restore_site' => $this->proposeForSite($command, 'restore_site', $parsed, 'שחזור אתר מהשעיה'),

            default => $this->finish($command, AgentCommandOutcome::Unclear, 'הפעולה אינה נתמכת עדיין.'),
        };
    }

    /** open_task: an optional customer, resolved leniently (skipped if ambiguous). */
    private function maybeCustomer(array $parsed): array
    {
        [$customer] = $this->resolveCustomer($parsed['customer_name'] ?? null);

        return $customer ? ['customer_id' => $customer->id] : [];
    }

    /** send_payment_request: needs a customer AND an amount; asks for whichever is missing. */
    private function proposePaymentRequest(AgentCommand $command, array $parsed, string $detail, ?int $agorot): AgentCommand
    {
        [$customer, $error] = $this->resolveCustomer($parsed['customer_name'] ?? null);
        if (! $customer) {
            return $this->finish($command, AgentCommandOutcome::Unclear, $error);
        }

        if ($agorot === null) {
            return $this->finish($command, AgentCommandOutcome::Unclear, "כמה לגבות מ{$customer->name}? ציינו סכום בשקלים.");
        }

        $command->customer_id = $customer->id;
        $description = $detail !== '' ? $detail : 'תשלום';
        $ils = number_format($agorot / 100, 2);

        return $this->propose($command, 'send_payment_request',
            ['customer_id' => $customer->id, 'amount_agorot' => $agorot, 'description' => $description, 'channel' => 'whatsapp'],
            "דרישת תשלום ל{$customer->name} על ₪{$ils} — {$description}");
    }

    /** An operation that only needs a resolved customer (e.g. mark_collected). */
    private function proposeForCustomer(AgentCommand $command, string $operation, array $parsed, callable $summary): AgentCommand
    {
        [$customer, $error] = $this->resolveCustomer($parsed['customer_name'] ?? null);
        if (! $customer) {
            return $this->finish($command, AgentCommandOutcome::Unclear, $error);
        }

        $command->customer_id = $customer->id;

        return $this->propose($command, $operation, ['customer_id' => $customer->id], $summary($customer));
    }

    /** An operation that needs a resolved site (suspend/restore). */
    private function proposeForSite(AgentCommand $command, string $operation, array $parsed, string $label): AgentCommand
    {
        [$site, $error] = $this->resolveSite($parsed['site_domain'] ?? null, $parsed['customer_name'] ?? null);
        if (! $site) {
            return $this->finish($command, AgentCommandOutcome::Unclear, $error);
        }

        $command->site_id = $site->id;
        $command->customer_id = $site->customer_id;

        return $this->propose($command, $operation, ['site_id' => $site->id], "{$label}: {$site->domain}");
    }

    /** open_task convenience wrapper (no entity resolution needed beyond the optional customer). */
    private function proposeSystemAction(AgentCommand $command, string $operation, array $payload, string $summary): AgentCommand
    {
        $command->customer_id = $payload['customer_id'] ?? null;

        return $this->propose($command, $operation, $payload, $summary);
    }

    /** File a system_action proposal for approval and record it on the command. */
    private function propose(AgentCommand $command, string $operation, array $payload, string $summary): AgentCommand
    {
        $action = $this->gate->propose(
            type: 'system_action',
            summary: "🛠️ פעולת מערכת — {$summary}",
            payload: ['operation' => $operation] + $payload + ['source' => 'command_console'],
            customerId: $command->customer_id,
            proposedBy: 'console',
        );

        $command->pending_action_id = $action->id;

        $gate = config('agent.system_actions_enabled')
            ? 'לאישור וביצוע'
            : 'לאישור (שימו לב: ביצוע פעולות מערכת כבוי — הפעילו בהגדרות סוכן AI)';

        return $this->finish($command, AgentCommandOutcome::Proposed,
            "{$summary} — הוגש {$gate} (#{$action->id}) במסך אישורי האוטומציה.");
    }

    /**
     * Resolve which customer the instruction is about, by name/contact.
     *
     * @return array{0: ?Customer, 1: string} [customer, error]
     */
    private function resolveCustomer(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return [null, 'לא זוהה לקוח. ציינו שם לקוח.'];
        }

        $customers = Customer::query()
            ->where('name', 'like', "%{$name}%")
            ->orWhere('contact_name', 'like', "%{$name}%")
            ->get();

        return match ($customers->count()) {
            0 => [null, "לא נמצא לקוח בשם \"{$name}\"."],
            1 => [$customers->first(), ''],
            default => [null, "נמצאו כמה לקוחות שמתאימים ל\"{$name}\". דייקו את השם."],
        };
    }

    /**
     * Resolve which ticket the instruction is about.
     *
     * @return array{0: ?Ticket, 1: string} [ticket, error]
     */
    private function resolveTicket(?int $ticketId, ?string $name): array
    {
        if ($ticketId) {
            $ticket = Ticket::find($ticketId);

            return $ticket
                ? [$ticket, '']
                : [null, "לא נמצאה פנייה #{$ticketId}."];
        }

        $name = trim((string) $name);
        if ($name === '') {
            return [null, 'לא זוהתה פנייה. ציינו שם לקוח או מספר פנייה — למשל "תענה למשה בכרטיס הפתוח ש...".'];
        }

        // Prefer still-open conversations; a reply to a resolved ticket is rare.
        $tickets = Ticket::query()
            ->whereIn('status', [TicketStatus::Open, TicketStatus::Pending, TicketStatus::OnHold])
            ->where(function ($q) use ($name): void {
                $q->where('contact_name', 'like', "%{$name}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$name}%")
                        ->orWhere('contact_name', 'like', "%{$name}%"));
            })
            ->latest('updated_at')
            ->get();

        if ($tickets->isEmpty()) {
            return [null, "לא נמצאה פנייה פתוחה שמתאימה ל\"{$name}\". בדקו את השם או ציינו מספר פנייה."];
        }

        // One customer with open tickets → take their most recent. Several
        // different customers match the name → ask for a ticket number.
        if ($tickets->pluck('customer_id')->unique()->count() > 1) {
            return [null, "נמצאו פניות פתוחות לכמה לקוחות שמתאימים ל\"{$name}\". ציינו מספר פנייה כדי לדייק."];
        }

        return [$tickets->first(), ''];
    }

    /**
     * Resolve which site the instruction is about (by domain, else by customer).
     *
     * @return array{0: ?Site, 1: string} [site, error]
     */
    private function resolveSite(?string $domain, ?string $name): array
    {
        $domain = preg_replace('#^https?://#i', '', trim((string) $domain));
        $domain = trim((string) $domain, '/ ');

        if ($domain !== '') {
            $sites = Site::where('domain', 'like', "%{$domain}%")->get();
        } elseif (trim((string) $name) !== '') {
            $name = trim((string) $name);
            $sites = Site::whereHas('customer', fn ($c) => $c
                ->where('name', 'like', "%{$name}%")
                ->orWhere('contact_name', 'like', "%{$name}%"))->get();
        } else {
            return [null, 'לא זוהה אתר. ציינו דומיין — למשל "תנקה קאש באתר example.co.il".'];
        }

        return match ($sites->count()) {
            0 => [null, 'לא נמצא אתר מתאים. בדקו את הדומיין.'],
            1 => [$sites->first(), ''],
            default => [null, 'נמצאו כמה אתרים מתאימים. ציינו דומיין מלא ומדויק.'],
        };
    }

    /** Ground a reply in the ticket's conversation, the operator's instruction and the team style. */
    private function draftReply(Ticket $ticket, string $detail): string
    {
        $ticket->loadMissing('customer');

        $conversation = $ticket->messages()
            ->where('channel', '!=', MessageChannel::InternalNote)
            ->latest('created_at')->limit(8)->get()
            ->reverse()
            ->map(fn ($m) => "[{$m->author->value}] ".Str::limit((string) $m->body, 600))
            ->implode("\n");

        $result = $this->ai->structured(
            system: $this->replySystemPrompt(),
            prompt: implode("\n\n", array_filter([
                'לקוח: '.($ticket->customer?->name ?? 'לא מזוהה'),
                'נושא הפנייה: '.$ticket->subject,
                $conversation !== '' ? "השיחה עד כה:\n{$conversation}" : null,
                "הוראת המפעיל (מה למסור ללקוח): {$detail}",
                'נסח תשובה קצרה, מנומסת ומקצועית ללקוח לפי ההוראה. אל תמציא מידע שלא נמסר.',
            ])),
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['reply' => ['type' => 'string']],
                'required' => ['reply'],
            ],
        );

        return trim((string) ($result['reply'] ?? ''));
    }

    /** The reply persona + team rules + learned style (same source as ticket drafts). */
    private function replySystemPrompt(): string
    {
        $style = trim((string) config('billing.ai.style_summary'));

        return implode("\n", array_filter([
            trim((string) config('billing.ai.persona')),
            '',
            'כללים מחייבים:',
            trim((string) config('billing.ai.rules')),
            $style !== '' ? "\nסגנון הצוות (נלמד מתשובות קודמות) — נסח בהתאם:\n{$style}" : null,
            '',
            'התשובה תעבור אישור אנושי לפני שליחה — אל תתחייב בשם החברה.',
        ], fn ($line) => $line !== null));
    }

    /** Persist the outcome + human result and return the record. */
    private function finish(AgentCommand $command, AgentCommandOutcome $outcome, string $result): AgentCommand
    {
        $command->outcome = $outcome;
        $command->result = $result;
        $command->save();

        return $command;
    }
}
