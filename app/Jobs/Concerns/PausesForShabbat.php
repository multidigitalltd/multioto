<?php

namespace App\Jobs\Concerns;

use App\Services\Calendar\ShabbatClock;

/**
 * Lets a CUSTOMER-FACING job hold itself over Shabbat / Yom Tov. The scheduler
 * already avoids dispatching most outward work during the quiet period, but a
 * job dispatched moments before candle lighting — or delayed by queue latency —
 * could still begin inside it. Calling rescheduledForShabbat() at the top of
 * handle() re-queues the job for the resume time (the day after) and stops the
 * current run, so nothing reaches a customer during the rest.
 *
 * Only jobs that message customers (charges, reminders, broadcasts, ticket
 * follow-ups) should use this. Internal monitoring checks and anything an
 * operator triggers by hand must run immediately, Shabbat or not.
 */
trait PausesForShabbat
{
    /** Re-queue this job for after the rest and return true, when blocked now. */
    protected function rescheduledForShabbat(): bool
    {
        $clock = app(ShabbatClock::class);

        if (! $clock->isBlocked()) {
            return false;
        }

        static::dispatch(...$this->shabbatDispatchArgs())->delay($clock->resumeAt());

        return true;
    }

    /**
     * Constructor arguments used to re-queue this job. Jobs with constructor
     * parameters override this to pass them through.
     *
     * @return array<int, mixed>
     */
    protected function shabbatDispatchArgs(): array
    {
        return [];
    }
}
