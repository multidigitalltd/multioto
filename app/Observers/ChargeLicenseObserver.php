<?php

namespace App\Observers;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Services\Licensing\LicenseRenewal;
use App\Services\Licensing\PluginCheckout;

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
    public function __construct(
        private LicenseRenewal $renewal,
        private PluginCheckout $checkout,
    ) {}

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
        if ($charge->status !== ChargeStatus::Succeeded) {
            return;
        }

        // Bookkeeping must never undo a collected payment: a licence table that
        // is briefly unavailable is a problem to look at, not a reason to fail
        // the charge that has already left the customer's card.
        try {
            if ($charge->subscription_id !== null) {
                $this->renewal->applyTo($charge);
            }

            // A self-service purchase: the money has arrived, so the licence is
            // issued and emailed. Here rather than in the webhook, because a
            // payment is also confirmed by the reconciliation that finishes a
            // charge whose webhook was lost — and somebody who paid must not
            // depend on which of the two happened.
            $this->checkout->fulfil($charge);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
