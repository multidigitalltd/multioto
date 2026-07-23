<?php

namespace App\Jobs;

use App\Enums\ActionStatus;
use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The last leg of the end-to-end auto-heal loop: a site went down, an
 * automation fix was proposed, a manager APPROVED it, the fix ran, and the site
 * recovered — so proactively tell the customer we detected and fixed the
 * problem before they even noticed. Dispatched from MonitorSiteJob exactly once
 * per incident, at the moment of recovery; sends nothing when no approved fix
 * actually executed during the incident (an ordinary blip stays quiet).
 */
class NotifyIncidentAutoResolvedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $siteId, public int $incidentId) {}

    public function handle(TemplateEngine $templates, WahaClient $waha): void
    {
        if (! config('billing.monitoring.notify_customer_after_auto_fix', true)) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);
        $incident = Incident::find($this->incidentId);

        if (! $site || ! $site->customer || ! $incident || $incident->started_at === null) {
            return;
        }

        // Only when an APPROVED automation/AI fix actually ran INSIDE the outage
        // window — a site that recovered on its own gets no "we fixed it"
        // message. proposed_by filters out a team member's manual "פעולת AI"
        // (proposedBy: 'team'), which is maintenance, not auto-heal; the upper
        // bound keeps out actions executed after recovery (e.g. when this queued
        // job ran late) that could not have fixed the already-resolved incident.
        $fix = PendingAction::query()
            ->whereIn('type', ['site_fix', 'site_action'])
            ->whereIn('proposed_by', ['automation', 'ai'])
            ->where('status', ActionStatus::Executed)
            ->where('payload->site_id', $site->id)
            ->where('executed_at', '>=', $incident->started_at)
            ->where('executed_at', '<=', $incident->resolved_at ?? now())
            ->latest('executed_at')
            ->first();

        if ($fix === null) {
            return;
        }

        $customer = $site->customer;

        $data = [
            'customer_name' => $customer->contact_name ?: $customer->name,
            'business_name' => config('mail.from.name') ?: config('app.name'),
            'domain' => $site->domain,
            'fix' => $this->fixLabel($fix),
            'downtime' => $this->downtime($incident),
        ];

        // Per-channel best-effort — an email outage never blocks the WhatsApp
        // message (and vice versa), mirroring every other customer notification.
        if (filled($customer->email) && ($email = $templates->render('incident.auto_resolved', 'email', $data))) {
            try {
                Mail::to($customer->email)->send(new NotificationMail($email['subject'] ?? 'התקלה טופלה', $email['body']));
                NotificationLog::record('email', NotificationType::IncidentResolved, $customer->email, $email['subject'] ?? null, $email['body'], $customer->id);
            } catch (\Throwable $e) {
                Log::warning('Incident auto-resolved email failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
                NotificationLog::record('email', NotificationType::IncidentResolved, $customer->email, $email['subject'] ?? null, $email['body'], $customer->id, 'failed', $e->getMessage());
            }
        }

        $recipient = $customer->whatsappRecipient();
        if (filled($recipient) && ($wa = $templates->render('incident.auto_resolved', 'whatsapp', $data))) {
            try {
                $waha->sendMessage($recipient, $wa['body']);
                NotificationLog::record('whatsapp', NotificationType::IncidentResolved, $recipient, null, $wa['body'], $customer->id);
            } catch (\Throwable $e) {
                Log::warning('Incident auto-resolved WhatsApp failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
                NotificationLog::record('whatsapp', NotificationType::IncidentResolved, $recipient, null, $wa['body'], $customer->id, 'failed', $e->getMessage());
            }
        }
    }

    /** Customer-friendly Hebrew description of what the executed fix was. */
    protected function fixLabel(PendingAction $action): string
    {
        if ($action->type === 'site_fix') {
            $fix = (string) data_get($action->payload, 'fix');

            return SiteDiagnostics::FIX_LABELS[$fix] ?? 'תיקון אוטומטי';
        }

        // site_action — an AI-proposed tool call; the tool name is technical, so
        // keep the customer-facing wording generic.
        return 'תיקון אוטומטי באתר';
    }

    /** Human-readable downtime duration in Hebrew (e.g. "12 דקות"). */
    protected function downtime(Incident $incident): string
    {
        $end = $incident->resolved_at ?? now();
        $minutes = max(1, (int) $incident->started_at->diffInMinutes($end));

        if ($minutes < 60) {
            return $minutes === 1 ? 'דקה אחת' : "{$minutes} דקות";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours === 1 ? 'שעה' : "{$hours} שעות").($rest > 0 ? " ו-{$rest} דקות" : ''));
    }
}
