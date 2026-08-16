<?php

namespace App\Observers;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Services\Licensing\LicenseRenewal;

/**
 * Move a licence's expiry the moment its charge succeeds.
 *
 * Hooked on the model rather than called from the charging job, because a
 * charge reaches "succeeded" by more than one route — the scheduled run, a
 * manual charge, the reconciliation that finishes a charge whose webhook was
 * lost. A customer whose renewal was collected through the least common of
 * those must not be the one who loses their updates, and the only place all
 * three meet is here.
 *
 * Idempotent: renewal only ever moves an expiry forward, so a row saved twice
 * changes nothing the second time.
 */
class ChargeLicenseObserver
{
    public function __construct(private LicenseRenewal $renewal) {}

    public function created(Charge $charge): void
    {
        $this->renew($charge);
    }

    public function updated(Charge $charge): void
    {
        $this->renew($charge);
    }

    private function renew(Charge $charge): void
    {
        if ($charge->status !== ChargeStatus::Succeeded || $charge->subscription_id === null) {
            return;
        }

        // Bookkeeping must never undo a collected payment: a licence table that
        // is briefly unavailable is a problem to look at, not a reason to fail
        // the charge that has already left the customer's card.
        try {
            $this->renewal->applyTo($charge);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
