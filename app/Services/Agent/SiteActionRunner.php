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
        private RevertRecipe $recipes,
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

        return $this->execute($action, $site, $tool, $arguments, Str::limit($action->summary, 250));
    }

    /**
     * One tool call, policy-checked, executed and journalled against $action.
     *
     * Separate from run() so a batch — one approval covering many products —
     * goes through exactly this path per call, rather than a parallel
     * implementation that would eventually drift from it on the checks that
     * matter. Every change it writes carries the batch's own pending_action_id,
     * which is what makes the group recoverable as a group.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(PendingAction $action, Site $site, string $tool, array $arguments, string $summary, ?int $revertsChangeId = null): string
    {
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

        // Core file operations (update/rollback) download and swap WordPress and
        // can exceed the default per-call timeout — give them the same long window
        // the update path uses, so a rollback isn't wrongly recorded as failed.
        $timeout = in_array($tool, ['wp_core_update', 'wp_core_rollback'], true)
            ? (int) config('agent.mcp.core_update_timeout_seconds', 300)
            : 0;

        try {
            $result = $this->mcp->callTool($site, $tool, $arguments, $timeout);
        } catch (\Throwable $e) {
            // A failed attempt is part of the site's history too.
            $this->journal->record(
                $site,
                summary: $summary,
                tool: $tool,
                arguments: $arguments,
                initiatedBy: $action->proposed_by,
                pendingAction: $action,
                status: SiteChangeStatus::Failed,
            )->update(['error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $output = $this->mcp->textContent($result);

        // A successful revert closes the original change in the journal. The
        // caller may name it per call (a batch undoes many at once); otherwise
        // it comes from the proposal, as it always has.
        $revertsId = $revertsChangeId ?? data_get($action->payload, 'reverts_change_id');

        if ($revertsId !== null) {
            if ($original = SiteChange::where('site_id', $site->id)->find((int) $revertsId)) {
                $this->journal->markReverted($original);
            }
        }

        // Journal state-changing tools only — a read leaves nothing to undo and
        // would drown the change history in noise. The optional `revert` recipe
        // (an inverse tool + arguments) is stored so the change can be rolled
        // back live later.
        if ($this->catalog->resolveTier($site, $tool) >= 1) {
            // An explicitly proposed recipe wins — somebody who knows how to
            // undo their own operation knows better than a general rule. When
            // there is none, derive it from what the tool reported it replaced,
            // which is the only source that reflects the site as it really was
            // a moment ago rather than as anyone predicted.
            $revert = (array) data_get($action->payload, 'revert', []);

            if (blank($revert['tool'] ?? null)) {
                $revert = $this->recipes->for($tool, $arguments, $output) ?? [];
            }

            $this->journal->record(
                $site,
                summary: $summary,
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
