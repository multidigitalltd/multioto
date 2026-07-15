<?php

namespace App\Services\Agent;

use App\Models\Site;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Support\Str;

/**
 * The AI operator for a site. It investigates using READ-ONLY MCP tools and,
 * when a change is warranted, PROPOSES exactly one action through the approval
 * gate — it never executes anything itself. Execution stays behind the manager
 * approval and the master kill-switch (SiteActionRunner). This is the layer
 * that turns "the team picks a tool" into "the AI proposes, a manager approves".
 */
class SiteAgent
{
    public function __construct(
        private ClaudeClient $ai,
        private McpClient $mcp,
        private SiteToolCatalog $catalog,
        private ApprovalGate $gate,
        private SiteMemoryStore $memory,
    ) {}

    /**
     * Investigate a site toward a goal (an incident, a ticket, a manual "AI
     * diagnose"). Returns the AI's written summary, or null when the AI is
     * unavailable or the site isn't connected.
     */
    public function investigate(Site $site, string $goal): ?string
    {
        if (! $this->ai->isEnabled() || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return null;
        }

        // Only tools the site itself declares read-only (MCP readOnlyHint) may be
        // offered as reads — never a tool that merely has a read-ish name.
        $readTools = collect((array) data_get($site->mcp_capabilities, 'tools', []))
            ->pluck('name')
            ->filter(fn ($name): bool => filled($name) && $this->catalog->isReadOnly($site, (string) $name))
            ->values();

        return $this->ai->converse(
            system: $this->systemPrompt($site),
            prompt: $goal,
            tools: $this->toolDefinitions($readTools->all()),
            handler: fn (string $name, array $input): array => $this->handleToolCall($site, $name, $input),
        );
    }

    /** The tools offered to the model: one read tool and one propose tool. */
    private function toolDefinitions(array $readToolNames): array
    {
        $tools = [[
            'name' => 'propose_action',
            'description' => 'הצע פעולה אחת לתיקון האתר. הפעולה לא מתבצעת מיד — היא נשלחת לאישור מנהל. '
                .'ציין את שם הכלי, הפרמטרים, וסיכום קצר. אם ידוע כיצד לבטל את השינוי, ציין revert_tool ו-revert_arguments.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tool' => ['type' => 'string', 'description' => 'שם כלי ה-MCP לביצוע'],
                    'arguments' => ['type' => 'object', 'description' => 'פרמטרים לכלי'],
                    'summary' => ['type' => 'string', 'description' => 'סיכום קצר של הפעולה בעברית'],
                    'revert_tool' => ['type' => 'string', 'description' => 'כלי לביטול השינוי (אופציונלי)'],
                    'revert_arguments' => ['type' => 'object', 'description' => 'פרמטרים לביטול (אופציונלי)'],
                ],
                'required' => ['tool', 'summary'],
            ],
        ]];

        if ($readToolNames !== []) {
            $tools[] = [
                'name' => 'site_read',
                'description' => 'הרץ כלי קריאה בלבד על האתר כדי לחקור את מצבו (לוגים, רשימת תוספים, בריאות וכו׳). '
                    .'כלים זמינים: '.implode(', ', $readToolNames),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool' => ['type' => 'string', 'enum' => $readToolNames, 'description' => 'שם כלי הקריאה'],
                        'arguments' => ['type' => 'object', 'description' => 'פרמטרים לכלי'],
                    ],
                    'required' => ['tool'],
                ],
            ];
        }

        return $tools;
    }

    /** Execute one tool call the model made. */
    private function handleToolCall(Site $site, string $name, array $input): array
    {
        return match ($name) {
            'site_read' => $this->runRead($site, (string) ($input['tool'] ?? ''), (array) ($input['arguments'] ?? [])),
            'propose_action' => $this->proposeAction($site, $input),
            default => ['content' => "כלי לא מוכר: {$name}", 'is_error' => true],
        };
    }

    /** Run a read-only tool live and return its output to the model. */
    private function runRead(Site $site, string $tool, array $arguments): array
    {
        // Enforce read-only at execution, default-deny: the tool must be one the
        // site declared read-only. This is the ONLY path that skips approval, so
        // a mutating tool can never slip through on a read-ish name.
        if ($tool === '' || ! $this->catalog->isReadOnly($site, $tool)) {
            return ['content' => "הכלי {$tool} אינו כלי קריאה ולכן חסום כאן. להצעת שינוי השתמש ב-propose_action.", 'is_error' => true];
        }

        try {
            $result = $this->mcp->callTool($site, $tool, $arguments);

            return ['content' => Str::limit($this->mcp->textContent($result), 4000) ?: '(ללא פלט)'];
        } catch (\Throwable $e) {
            return ['content' => 'הקריאה נכשלה: '.Str::limit($e->getMessage(), 300), 'is_error' => true];
        }
    }

    /** Turn a proposed action into a PendingAction (never executes it). */
    private function proposeAction(Site $site, array $input): array
    {
        $tool = (string) ($input['tool'] ?? '');
        $summary = (string) ($input['summary'] ?? '');
        $arguments = (array) ($input['arguments'] ?? []);

        if ($tool === '' || $summary === '') {
            return ['content' => 'חסר שם כלי או סיכום.', 'is_error' => true];
        }

        if (! $this->catalog->allowedOn($site, $tool)) {
            return ['content' => "הכלי {$tool} מסווג כהרסני ומותר רק באתר סטייג׳ינג — לא ניתן להציע אותו כאן.", 'is_error' => true];
        }

        $payload = ['site_id' => $site->id, 'tool' => $tool, 'arguments' => $arguments];

        if (filled($input['revert_tool'] ?? null)) {
            $payload['revert'] = ['tool' => (string) $input['revert_tool'], 'arguments' => (array) ($input['revert_arguments'] ?? [])];
        }

        $action = $this->gate->propose(
            type: 'site_action',
            summary: "🤖 הצעת AI לאתר {$site->domain} ({$this->catalog->resolveTierLabel($site, $tool)})\n".Str::limit($summary, 400)."\nכלי: {$tool}",
            payload: $payload,
            customerId: $site->customer_id,
            proposedBy: 'ai',
        );

        return ['content' => "הפעולה הוצעה (#{$action->id}) ונשלחה לאישור מנהל. אל תציע אותה שוב."];
    }

    private function systemPrompt(Site $site): string
    {
        $memory = collect($this->memory->all($site))
            ->map(fn ($value, $key): string => "- {$key}: {$value}")
            ->implode("\n");

        $rules = trim((string) config('billing.ai.rules'));

        return trim(<<<PROMPT
            אתה עוזר תפעול לאתרי וורדפרס של Multi Digital. תפקידך לאבחן את האתר {$site->domain} ולתקן תקלות.

            כללי עבודה מחייבים:
            - חקור תמיד קודם עם site_read (קריאה בלבד). אל תמציא מצב — בדוק אותו.
            - אתה לא משנה דבר בעצמך. אם נדרש תיקון — הצע פעולה אחת בלבד עם propose_action; היא תעבור אישור מנהל לפני ביצוע.
            - הצע את הפעולה הבטוחה והמינימלית שפותרת את הבעיה. אם אין צורך בשינוי — אמור זאת וסיים.
            - בסיום כתוב סיכום קצר וברור בעברית: מה מצאת, ומה הצעת (אם הצעת).

            {$rules}

            מה שידוע לנו על האתר:
            {$memory}
            PROMPT);
    }
}
