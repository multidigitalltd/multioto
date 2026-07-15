<?php

namespace App\Services\Agent;

use App\Enums\SiteChangeStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteChange;

/**
 * Records every change the agent applies to a site, together with the prior
 * state needed to undo it. This is the site "sandbox": a full, per-site history
 * of what changed and a handle to roll each change back.
 *
 * This service owns the journal only — actually re-applying the prior state on
 * the live site is the MCP execution layer's job, which calls markReverted()
 * once the site confirms the rollback.
 */
class SiteChangeJournal
{
    /**
     * Log a change that was applied to a site.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function record(
        Site $site,
        string $summary,
        ?string $tool = null,
        array $arguments = [],
        ?string $beforeState = null,
        ?string $afterState = null,
        ?string $initiatedBy = null,
        ?PendingAction $pendingAction = null,
        SiteChangeStatus $status = SiteChangeStatus::Applied,
        ?string $revertTool = null,
        ?array $revertArguments = null,
    ): SiteChange {
        return $site->changes()->create([
            'summary' => $summary,
            'tool' => $tool,
            'arguments' => $arguments,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'initiated_by' => $initiatedBy,
            'pending_action_id' => $pendingAction?->id,
            'status' => $status,
            'revert_tool' => $revertTool,
            'revert_arguments' => $revertArguments,
        ]);
    }

    /**
     * Mark a change as rolled back. Called after the site confirms the prior
     * state was restored; a change that carries no before-state can't be undone.
     */
    public function markReverted(SiteChange $change): void
    {
        if (! $change->isRevertable()) {
            return;
        }

        $change->update([
            'status' => SiteChangeStatus::Reverted,
            'reverted_at' => now(),
        ]);
    }
}
