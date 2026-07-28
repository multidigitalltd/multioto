<?php

namespace App\Filament\Resources\BroadcastResource\Concerns;

use App\Enums\BroadcastStatus;

/**
 * The status of a broadcast is derived from its send time, never picked by
 * hand — that is how a broadcast could be marked "נשלח" without a single
 * customer receiving it.
 *
 * Both the create and the edit screen must apply this: the scheduler only
 * dispatches rows whose status is `scheduled`, so a broadcast that gets its
 * send time on the CREATE screen and keeps the column default (`draft`) would
 * sit there silently and never go out.
 */
trait DerivesBroadcastStatus
{
    protected function deriveBroadcastStatus(array $data): array
    {
        $data['status'] = filled($data['scheduled_at'] ?? null)
            ? BroadcastStatus::Scheduled
            : BroadcastStatus::Draft;

        return $data;
    }
}
