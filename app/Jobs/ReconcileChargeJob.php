<?php

namespace App\Jobs;

use App\Models\Charge;
use App\Services\Cardcom\ChargeReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Check one pending manual charge against Cardcom and finalise it if it went
 * through (recovers a lost webhook / crashed charge job). Dispatched by the
 * scheduler; the reconciler only ever confirms a success, never a re-charge.
 */
class ReconcileChargeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $chargeId) {}

    public function handle(ChargeReconciler $reconciler): void
    {
        $charge = Charge::find($this->chargeId);

        if ($charge) {
            $reconciler->reconcile($charge);
        }
    }
}
