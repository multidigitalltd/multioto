<?php

namespace App\Jobs;

use App\Models\SystemLog;
use App\Services\SiteAgent\MessagingCostReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask Meta what the bot's messages cost, once a day.
 *
 * A queued job and not a page load, by the architecture rule: this is an
 * outbound HTTP call to a third party, and the cost screen must open in
 * milliseconds whether or not Meta is answering today.
 *
 * Failing to reach Meta is not an error worth retrying hard. The figures are a
 * daily report, the previous day's remain on screen with their date, and the
 * reason is recorded where the operator is already looking — so one attempt,
 * and a line in the journal when it does not work.
 */
class SyncSiteAgentMessagingCostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Null means every window the screen offers. */
    public function __construct(public ?int $days = null) {}

    public function handle(MessagingCostReport $report): void
    {
        if (! (bool) config('siteagent.enabled')) {
            return;
        }

        /*
         | Every window the screen offers, because the cost is cached per window:
         | Meta is asked about a period, and the 7-day view must not show a
         | 30-day figure. Three small calls once a day.
         */
        $windows = $this->days === null ? MessagingCostReport::WINDOWS : [$this->days];
        $failed = [];

        foreach ($windows as $days) {
            $result = $report->refresh($days);

            if (! $result['ok']) {
                $failed[$days] = $result['reason'] ?: 'לא צוינה סיבה.';
            }
        }

        if ($failed === []) {
            return;
        }

        // One line however many windows failed, because they fail together and
        // for the same reason. And the reason matters: "COST is withheld for an
        // account on a partner's credit line" and "the token expired" both look
        // like no data on the screen, and only one of them is fixable.
        SystemLog::record('warning', 'siteagent',
            'לא התקבלו נתוני עלות הודעות ממטא: '.reset($failed),
            ['windows' => array_keys($failed)]);
    }
}
