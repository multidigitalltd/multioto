<?php

namespace App\Jobs;

use App\Models\PendingSignup;
use App\Models\SystemLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Delete signups that were filled in and never finished.
 *
 * These rows hold a name, a phone, an email and a drawn signature for somebody
 * who is not a customer and never became one. Keeping them indefinitely would
 * quietly turn a step in a form into a store of personal details about people
 * who walked away — so an abandoned signup is removed once its link has expired,
 * and its signature file goes with it rather than being orphaned on the disk.
 *
 * Completed signups are kept: they are the record of how an existing customer
 * came to be, and their signature now belongs to that customer.
 */
class PrunePendingSignupsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = now()->subHours(PendingSignup::lifetimeHours());
        $removed = 0;

        PendingSignup::query()
            ->whereNull('completed_at')
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($signups) use (&$removed): void {
                foreach ($signups as $signup) {
                    $this->forgetSignature($signup);
                    $signup->delete();
                    $removed++;
                }
            });

        if ($removed > 0) {
            SystemLog::record('info', 'billing', "נוקו {$removed} הרשמות שלא הושלמו (כולל קבצי החתימה)");
        }
    }

    /**
     * Remove the drawn signature from the private disk.
     *
     * Best-effort: a file that is already gone is not a failure, and a delete
     * that throws must not stop the row itself from being removed — leaving the
     * record because the file could not be deleted would keep exactly the
     * personal details this job exists to clear.
     */
    private function forgetSignature(PendingSignup $signup): void
    {
        if (blank($signup->signature_path)) {
            return;
        }

        try {
            Storage::disk('local')->delete($signup->signature_path);
        } catch (\Throwable $e) {
            Log::warning('PrunePendingSignupsJob: signature could not be deleted', [
                'pending_signup_id' => $signup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
