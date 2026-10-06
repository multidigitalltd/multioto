<?php

namespace App\Jobs;

use App\Models\SiteAgentMessage;
use App\Models\SiteAgentReportSchedule;
use App\Models\SystemLog;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\SiteAgentMessageCap;
use App\Services\SiteAgent\SiteAgentReportBuilder;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the reports owners ordered from the bot, when they fall due.
 *
 * A scheduled report is a message WE start, and Meta treats those differently
 * from a reply. Inside the 24 hours after the owner last wrote, free text is
 * allowed and the whole report goes as it is. Outside that window only an
 * approved template is accepted — so the report goes as one: its title, the
 * site and a one-line summary ("7 הזמנות · ₪2,340 · 3 לידים"), with an
 * invitation to reply "דוח". The reply opens the window, and the bot sends the
 * full report then. Without a template configured, a report that falls outside
 * the window cannot be delivered at all, and the team is told once rather
 * than the owner being told nothing.
 *
 * Each run is claimed by moving its next_run_at forward in one conditional
 * UPDATE before anything is sent: two overlapping runs of the scheduler can
 * both see the row as due, and only one of them gets to send it.
 */
class SendSiteAgentReportsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** Inside this, free text still reaches the owner. Kept short of Meta's 24 hours. */
    private const WINDOW_HOURS = 23;

    public function handle(
        SiteAgentAccess $access,
        SiteAgentReportBuilder $builder,
        WhatsAppCloudClient $whatsapp,
        SiteAgentUsageMeter $meter,
        SiteAgentAssistant $assistant,
    ): void {
        if (! (bool) config('siteagent.enabled', false)) {
            return;
        }

        SiteAgentReportSchedule::query()
            ->with(['subscriber.site', 'subscriber.customer', 'site'])
            ->where('next_run_at', '<=', now())
            ->chunkById(50, function ($schedules) use ($access, $builder, $whatsapp, $meter, $assistant): void {
                foreach ($schedules as $schedule) {
                    if (! $this->claim($schedule)) {
                        continue; // Another run took it.
                    }

                    $subscriber = $schedule->subscriber;

                    // Not paying, revoked or disconnected: the schedule waits
                    // for the next slot rather than sending into a closed door
                    // or piling up a backlog to flood them with later.
                    if ($subscriber === null || $schedule->site === null
                        || $access->forSubscriber($subscriber)['status'] !== SiteAgentAccess::ALLOWED) {
                        continue;
                    }

                    // The owner's own ceiling on messages is reached: a report
                    // is a message, and they asked for nothing above it.
                    $subscription = app(SiteAgentBilling::class)->subscriptionForSite($subscriber->customer, $subscriber->site_id);

                    if ($meter->capReached($subscription)) {
                        continue;
                    }

                    $report = $builder->build($schedule->site, $schedule->frequency, (array) $schedule->sections);
                    $windowOpen = $subscriber->last_seen_at !== null
                        && $subscriber->last_seen_at->gt(now()->subHours(self::WINDOW_HOURS));

                    $sent = $windowOpen
                        ? $whatsapp->sendText($subscriber->phone, $report['text'])
                        : $this->sendTemplate($whatsapp, $subscriber->phone, $schedule, $report);

                    if ($sent === null) {
                        continue;
                    }

                    $schedule->forceFill(['last_sent_at' => now()])->save();
                    $meter->record($subscriber, $sent);
                    app(SiteAgentMessageCap::class)->warnIfDue($whatsapp, $subscriber->phone, $subscription);

                    // In the conversation's memory, so a "דוח" in reply is
                    // understood as "the full version of that one".
                    $assistant->remember($subscriber, SiteAgentMessage::ASSISTANT, $windowOpen
                        ? $report['text']
                        : "[נשלח תקציר של {$report['title']}: {$report['summary']}. אם בעל האתר משיב \"דוח\" — שלח את הדוח המלא עם report_now, frequency={$schedule->frequency}.]");
                }
            });
    }

    /** Move the schedule on before sending; false when another run got there first. */
    private function claim(SiteAgentReportSchedule $schedule): bool
    {
        $next = $schedule->nextRunAfter(now());

        $claimed = SiteAgentReportSchedule::query()
            ->whereKey($schedule->id)
            ->where('next_run_at', $schedule->getRawOriginal('next_run_at'))
            ->update(['next_run_at' => $next]);

        return $claimed === 1;
    }

    /**
     * @param  array{text: string, summary: string, title: string}  $report
     */
    private function sendTemplate(WhatsAppCloudClient $whatsapp, string $phone, SiteAgentReportSchedule $schedule, array $report): ?string
    {
        $template = (string) config('siteagent.whatsapp.templates.report_ready', '');

        if ($template === '') {
            SystemLog::record('warning', 'siteagent',
                'דוח קבוע לא נשלח: בעל האתר לא כתב לבוט ב-24 השעות האחרונות, ולא הוגדרה תבנית "דוח מוכן" בהגדרות בוט ניהול האתר.',
                ['schedule_id' => $schedule->id]);

            return null;
        }

        return $whatsapp->sendTemplate($phone, $template, [
            'title' => $report['title'],
            'domain' => (string) $schedule->site?->domain,
            'summary' => $report['summary'],
        ]);
    }
}
