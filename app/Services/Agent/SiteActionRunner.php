<?php

namespace App\Services\Agent;

use App\Enums\SiteChangeStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteChange;
use Illuminate\Support\Str;

/**
 * Executes an APPROVED site_action: policy-checks the tool against the site,
 * invokes it over MCP, and records the outcome — success or failure — in the
 * site's change journal. Called only from ApprovalGate::execute(), i.e. always
 * after a manager approved the exact proposal.
 *
 * payload shape: { site_id, tool, arguments?, before_state? }
 */
class SiteActionRunner
{
    public function __construct(
        private McpClient $mcp,
        private SiteToolCatalog $catalog,
        private SiteChangeJournal $journal,
    ) {}

    /** Run the approved action. Returns the tool's text output. */
    public function run(PendingAction $action): string
    {
        $site = Site::find((int) data_get($action->payload, 'site_id'));
        $tool = (string) data_get($action->payload, 'tool');
        $arguments = (array) data_get($action->payload, 'arguments', []);

        if (! $site || $tool === '') {
            throw new \RuntimeException('האתר או הכלי חסרים בהצעה.');
        }

        // Master kill-switch — approved or not, nothing runs on any site while
        // the site agent is turned off.
        if (! config('agent.actions_enabled')) {
            throw new \RuntimeException('מנגנון פעולות ה-AI כבוי (kill-switch). יש להפעיל אותו בהגדרות הסוכן.');
        }

        if (! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            throw new \RuntimeException("חיבור ה-AI לאתר {$site->domain} כבוי או לא מוגדר.");
        }

        if (! $this->catalog->allowedOn($site, $tool)) {
            throw new \RuntimeException("הכלי {$tool} מסווג כהרסני ומותר רק באתר סטייג׳ינג.");
        }

        try {
            $result = $this->mcp->callTool($site, $tool, $arguments);
        } catch (\Throwable $e) {
            // A failed attempt is part of the site's history too.
            $this->journal->record(
                $site,
                summary: Str::limit($action->summary, 250),
                tool: $tool,
                arguments: $arguments,
                initiatedBy: $action->proposed_by,
                pendingAction: $action,
                status: SiteChangeStatus::Failed,
            )->update(['error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $output = $this->mcp->textContent($result);

        // A successful revert closes the original change in the journal.
        if (($revertsId = data_get($action->payload, 'reverts_change_id')) !== null) {
            if ($original = SiteChange::where('site_id', $site->id)->find((int) $revertsId)) {
                $this->journal->markReverted($original);
            }
        }

        // Journal state-changing tools only — a read leaves nothing to undo and
        // would drown the change history in noise. The optional `revert` recipe
        // (an inverse tool + arguments) is stored so the change can be rolled
        // back live later.
        if ($this->catalog->resolveTier($site, $tool) >= 1) {
            $revert = (array) data_get($action->payload, 'revert', []);

            $this->journal->record(
                $site,
                summary: Str::limit($action->summary, 250),
                tool: $tool,
                arguments: $arguments,
                beforeState: data_get($action->payload, 'before_state'),
                afterState: Str::limit($output, 2000) ?: null,
                initiatedBy: $action->proposed_by,
                pendingAction: $action,
                revertTool: filled($revert['tool'] ?? null) ? (string) $revert['tool'] : null,
                revertArguments: isset($revert['arguments']) ? (array) $revert['arguments'] : null,
            );
        }

        return $output;
    }
}
