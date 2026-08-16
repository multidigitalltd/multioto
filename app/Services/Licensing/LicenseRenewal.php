<?php

namespace App\Services\Licensing;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\License;
use App\Models\SystemLog;

/**
 * A licence renews because a charge succeeded — nothing else moves its expiry.
 *
 * Deliberately built on the billing machine already here rather than beside it.
 * A licence sold on a subscription is charged, invoiced, retried and chased by
 * exactly the same code as everything else we sell; a second, licence-only
 * billing path would be a second place for money to go wrong, and the first
 * place anybody would forget to look.
 *
 * The new expiry is the END of the period that was paid for, so the licence and
 * the money always describe the same stretch of time. Never moved backwards: a
 * replayed or out-of-order charge must not shorten something already paid to
 * extend.
 */
class LicenseRenewal
{
    /**
     * Extend every licence carried by this charge's subscription.
     *
     * @return int how many licences actually moved
     */
    public function applyTo(Charge $charge): int
    {
        if ($charge->status !== ChargeStatus::Succeeded || $charge->subscription_id === null) {
            return 0;
        }

        $through = $charge->period_end;

        if ($through === null) {
            return 0;
        }

        $moved = 0;

        foreach (License::query()->where('subscription_id', $charge->subscription_id)->get() as $license) {
            if ($license->extendThrough($through)) {
                $moved++;

                SystemLog::record('info', 'licensing',
                    "רישיון {$license->key_prefix}… הוארך עד {$through->format('d/m/Y')} בעקבות חיוב שהצליח.",
                    ['license_id' => $license->id, 'charge_id' => $charge->id]);
            }
        }

        return $moved;
    }
}
