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

    public function __construct(public int $days = 30) {}

    public function handle(MessagingCostReport $report): void
    {
        if (! (bool) config('siteagent.enabled')) {
            return;
        }

        $result = $report->refresh($this->days);

        if ($result['ok']) {
            return;
        }

        // Recorded once a day at most, which is how often this runs. The reason
        // matters: "COST is not returned for accounts on a partner's credit
        // line" and "the token expired" both look like no data on the screen,
        // and only one of them is something anybody can fix.
        SystemLog::record('warning', 'siteagent',
            'לא התקבלו נתוני עלות הודעות ממטא: '.($result['reason'] ?: 'לא צוינה סיבה.'),
            ['days' => $this->days]);
    }
}
