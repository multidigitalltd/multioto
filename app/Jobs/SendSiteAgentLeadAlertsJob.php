<?php

namespace App\Jobs;

use App\Models\SiteAgentMessage;
use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentLeadAlerts;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Tells owners about new leads, a few minutes after they arrive.
 *
 * Each site is read once per run, however many of its numbers asked for
 * alerts. Within 24 hours of the owner last writing, each lead goes as its
 * own message with its fields. Outside that window Meta accepts only an
 * approved template, so the leads of that run go as one "דוח מוכן" template
 * naming the first of them, and a reply opens the window for the details.
 *
 * A burst — a form spammed, an import — is capped per run: the first few
 * are sent and the rest are counted in one closing line, so a number is
 * never flooded with messages it pays for.
 *
 * A lead is marked as seen only once its message went out. One that could
 * not be sent is tried again on the next run.
 */
class SendSiteAgentLeadAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Inside this, free text still reaches the owner. Kept short of Meta's 24 hours. */
    private const WINDOW_HOURS = 23;

    /** Leads sent one by one per number per run; the rest are counted. */
    private const PER_RUN = 3;

    public function handle(
        SiteAgentAccess $access,
        SiteAgentLeadAlerts $alerts,
        WhatsAppCloudClient $whatsapp,
        SiteAgentUsageMeter $meter,
        SiteAgentAssistant $assistant,
    ): void {
        if (! (bool) config('siteagent.enabled', false)) {
            return;
        }

        SiteAgentSubscriber::query()
            ->with(['site', 'customer'])
            ->where('lead_alerts', true)
            ->whereNotNull('site_id')
            ->get()
            ->filter(fn (SiteAgentSubscriber $subscriber): bool => $access->forSubscriber($subscriber)['status'] === SiteAgentAccess::ALLOWED)
            ->groupBy('site_id')
            ->each(function (Collection $subscribers) use ($alerts, $whatsapp, $meter, $assistant): void {
                $site = $subscribers->first()->site;
                $recent = $site !== null ? $alerts->recent($site) : null;

                if ($recent === null) {
                    return; // The site did not answer; the next run asks again.
                }

                foreach ($subscribers as $subscriber) {
                    $fresh = $alerts->fresh($subscriber, $recent['leads']);

                    if ($fresh !== []) {
                        $this->announce($subscriber, $fresh, $alerts, $whatsapp, $meter, $assistant);
                    }
                }
            });
    }

    /** @param list<array<string, mixed>> $fresh oldest first */
    private function announce(
        SiteAgentSubscriber $subscriber,
        array $fresh,
        SiteAgentLeadAlerts $alerts,
        WhatsAppCloudClient $whatsapp,
        SiteAgentUsageMeter $meter,
        SiteAgentAssistant $assistant,
    ): void {
        $windowOpen = $subscriber->last_seen_at !== null
            && $subscriber->last_seen_at->gt(now()->subHours(self::WINDOW_HOURS));

        if (! $windowOpen) {
            $this->announceByTemplate($subscriber, $fresh, $alerts, $whatsapp, $meter, $assistant);

            return;
        }

        $shown = array_slice($fresh, 0, self::PER_RUN);
        $rest = count($fresh) - count($shown);

        foreach ($shown as $index => $lead) {
            $text = $alerts->text($lead);

            if ($rest > 0 && $index === count($shown) - 1) {
                $text .= "\n\n…ועוד {$rest} לידים חדשים. כתבו \"לידים\" לרשימה המלאה.";
            }

            $sent = $whatsapp->sendText($subscriber->phone, $text);

            if ($sent === null) {
                return; // Not marked: the next run tries this one again.
            }

            $meter->record($subscriber, $sent);
            $assistant->remember($subscriber, SiteAgentMessage::ASSISTANT, $text);
            $alerts->markSeen($subscriber, $index === count($shown) - 1 ? array_slice($fresh, $index) : [$lead]);
        }
    }

    /** @param list<array<string, mixed>> $fresh */
    private function announceByTemplate(
        SiteAgentSubscriber $subscriber,
        array $fresh,
        SiteAgentLeadAlerts $alerts,
        WhatsAppCloudClient $whatsapp,
        SiteAgentUsageMeter $meter,
        SiteAgentAssistant $assistant,
    ): void {
        $template = (string) config('siteagent.whatsapp.templates.report_ready', '');

        if ($template === '') {
            // Nothing can reach them, and retrying every five minutes would
            // only repeat this line. The leads are on the site either way.
            SystemLog::record('warning', 'siteagent',
                'התראת ליד לא נשלחה: בעל האתר לא כתב לבוט ב-24 השעות האחרונות, ולא הוגדרה תבנית "דוח מוכן" בהגדרות בוט ניהול האתר.',
                ['subscriber_id' => $subscriber->id]);
            $alerts->markSeen($subscriber, $fresh);

            return;
        }

        $count = count($fresh);
        $title = $count === 1 ? 'ליד חדש' : "{$count} לידים חדשים";
        $summary = $alerts->summary($fresh[0]).($count > 1 ? ' ועוד' : '');

        $sent = $whatsapp->sendTemplate($subscriber->phone, $template, [
            'title' => $title,
            'domain' => (string) $subscriber->site?->domain,
            'summary' => $summary,
        ]);

        if ($sent === null) {
            return;
        }

        $meter->record($subscriber, $sent);
        $alerts->markSeen($subscriber, $fresh);
        $assistant->remember($subscriber, SiteAgentMessage::ASSISTANT,
            "[נשלחה התראה: {$title} — {$summary}. אם בעל האתר משיב \"דוח\" או \"לידים\" — הצג את הלידים החדשים עם find_leads.]");
    }
}
