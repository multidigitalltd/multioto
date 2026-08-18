<?php

namespace App\Jobs;

use App\Enums\ActionStatus;
use App\Models\PendingAction;
use App\Services\Agent\SiteActionBatchRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Carry on with an approved batch that is bigger than one slice.
 *
 * A sale across four hundred products is one decision and one approval, but it
 * cannot be four hundred shop calls inside the request that clicked "approve" —
 * that request would time out somewhere in the middle, leaving a shop half on
 * sale and a screen that says nothing useful about where it stopped.
 *
 * So the work is done in small slices and this job asks for the next one, until
 * there is no next one. Where to resume is not carried in the job: it is read
 * from the change journal, which is written as each call lands. That is what
 * makes a retry safe — a job that died after applying twelve of twenty resumes
 * at thirteen, because thirteen is what the journal says.
 */
class ContinueSiteActionBatchJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt per slice.
     *
     * A retry would re-enter a batch whose earlier calls already changed the
     * shop. Resuming is safe (the journal says where we are), but a slice that
     * failed for a real reason — a shop refusing writes — would otherwise be
     * retried against every remaining product in turn. The failure is recorded
     * on the action and a person decides.
     */
    public int $tries = 1;

    public function __construct(public int $pendingActionId) {}

    public function handle(SiteActionBatchRunner $batches): void
    {
        $action = PendingAction::find($this->pendingActionId);

        // Rejected, or already finished by another run: nothing owed.
        if (! $action || $action->status === ActionStatus::Failed) {
            return;
        }

        try {
            $batches->run($action);
        } catch (\Throwable $e) {
            // The batch stopped part-way. Recorded on the action so the panel
            // shows where it got to instead of a proposal that looks done.
            $action->update([
                'status' => ActionStatus::Failed,
                'error' => Str::limit($e->getMessage(), 300),
            ]);

            Log::warning('ContinueSiteActionBatchJob: batch stopped part-way', [
                'action' => $action->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
