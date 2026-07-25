<?php

namespace App\Jobs\Concerns;

use App\Models\SystemLog;
use App\Services\Calendar\ShabbatClock;

/**
 * Lets an outward-facing job hold itself over Shabbat / Yom Tov. The scheduler
 * already avoids dispatching most work during the quiet period, but a job
 * dispatched moments before candle lighting — or delayed by queue latency —
 * could still begin inside it. Calling rescheduledForShabbat() at the top of
 * handle() re-queues the job for the resume time (the day after) and stops the
 * current run, so nothing reaches a customer during the rest.
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

        $resume = $clock->resumeAt();

        static::dispatch(...$this->shabbatDispatchArgs())->delay($resume);

        // Jobs an operator can trigger by hand (the monitoring checks) name
        // themselves here, so a click during Shabbat leaves a visible trace in
        // the event log instead of appearing to do nothing.
        if (($held = $this->shabbatHoldDescription()) !== null) {
            $when = $resume !== null ? 'ב-'.$resume->format('d/m/Y H:i') : 'אחרי צאת השבת/החג';
            SystemLog::record('info', 'monitoring',
                "{$held} הושהתה לשבת/חג ותרוץ אוטומטית {$when}.",
                $this->shabbatHoldContext());
        }

        return true;
    }

    /**
     * A short Hebrew description of this job for the "held for Shabbat" event-
     * log entry (e.g. "בדיקת המוניטין לאתר X"), or null to hold silently.
     * Silent is the right default for bulk/outward jobs — logging every held
     * notification would flood the log.
     */
    protected function shabbatHoldDescription(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> Context stored with the hold log entry. */
    protected function shabbatHoldContext(): array
    {
        return [];
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
