<?php

namespace App\Jobs;

use App\Models\SiteAudit;
use App\Services\Audit\SiteAuditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Inspect one site and record what was found.
 *
 * On the queue because it is a dozen requests to somebody else's server, some
 * of which will be slow and some of which will time out — none of that belongs
 * inside a click. The row exists before the work starts, so an audit that dies
 * is visible as one that failed rather than as one that was never asked for.
 */
class RunSiteAuditJob implements ShouldQueue
{
    use Queueable;

    /**
     * Once. A site that did not answer is the finding, not a reason to knock
     * again — and knocking repeatedly on a prospect's server is a poor way to
     * introduce yourself.
     */
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $auditId) {}

    public function handle(SiteAuditor $auditor): void
    {
        $audit = SiteAudit::find($this->auditId);

        if ($audit === null || $audit->status !== SiteAudit::STATUS_RUNNING) {
            return;
        }

        try {
            $result = $auditor->run($audit->url);

            $audit->update([
                'status' => SiteAudit::STATUS_COMPLETED,
                'findings' => $result['findings'],
                'summary' => $result['summary'],
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $audit->update([
                'status' => SiteAudit::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
        }
    }

    /** A worker killed mid-audit must not leave the row saying "running" for ever. */
    public function failed(\Throwable $e): void
    {
        SiteAudit::whereKey($this->auditId)
            ->where('status', SiteAudit::STATUS_RUNNING)
            ->update([
                'status' => SiteAudit::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
    }
}
