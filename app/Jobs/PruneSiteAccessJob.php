<?php

namespace App\Jobs;

use App\Models\SiteInstallation;
use App\Models\SystemLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Forget customers' WordPress access once it has outlived the install.
 *
 * The install screen wipes the credential when somebody marks the job done, and
 * that covers the ordinary case. This covers the other one, which is the one
 * that actually happens: the customer installed it themselves in the end, or
 * the request was quietly dropped, and nobody ever pressed the button. Without
 * this, a row that nobody closed is a working admin login to a customer's site
 * sitting in our database forever.
 *
 * Deliberately blunt. It does not try to work out whether the access is still
 * needed — it wipes what the customer's own deadline has passed, and what
 * nothing has touched in weeks. Getting this wrong costs one message asking for
 * a fresh link; getting the other direction wrong costs a customer their site.
 */
class PruneSiteAccessJob implements ShouldQueue
{
    use Queueable;

    /**
     * How long a handover with no stated expiry is kept.
     *
     * Long enough that a normal install is never inconvenienced by it, short
     * enough that "we still have it" is never the answer a month later.
     */
    public const FALLBACK_DAYS = 14;

    public function handle(): void
    {
        $stale = SiteInstallation::query()
            ->accessStale(self::FALLBACK_DAYS)
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        foreach ($stale as $installation) {
            $installation->clearAccess();
        }

        // Recorded because the team will meet this as "the access I could see
        // yesterday is gone", and the screen says נמחקה without saying why.
        SystemLog::record('info', 'siteagent',
            'נמחקו פרטי גישה שפג תוקפם ב-'.$stale->count().' בקשות התקנה.',
            ['ids' => $stale->pluck('id')->all()]);
    }
}
