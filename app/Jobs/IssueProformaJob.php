<?php

namespace App\Jobs;

use App\Jobs\Concerns\WaitsForRestore;
use App\Models\Charge;
use App\Services\Linet\ProformaIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Issue a Linet proforma ("חשבונית עסקה") for a payment demand. Safe to retry
 * and a no-op once a proforma exists (or when no proforma document type is
 * configured). The work lives in ProformaIssuer, shared with any manual trigger.
 */
class IssueProformaJob implements ShouldQueue
{
    use Queueable;
    use WaitsForRestore;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $chargeId) {}

    /** @return array<int, mixed> */
    protected function backupWaitDispatchArgs(): array
    {
        return [$this->chargeId];
    }

    public function handle(ProformaIssuer $issuer): void
    {
        // Held rather than failed: the issuer refuses during a restore anyway,
        // and a refusal here would spend one of this job's few attempts on a
        // condition that clears by itself.
        if ($this->heldForBackupOperation()) {
            return;
        }

        $charge = Charge::find($this->chargeId);

        if (! $charge) {
            return;
        }

        $result = $issuer->issue($charge);

        // Throw so the job retries a transient Linet error. A misconfiguration
        // keeps failing until fixed — visible in the failed-jobs list — but it
        // never blocks the demand itself, which was already sent.
        if (! $result['ok']) {
            throw new \RuntimeException('Linet proforma failed: '.($result['error'] ?? 'unknown'));
        }
    }
}
