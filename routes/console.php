<?php

use App\Enums\BroadcastStatus;
use App\Enums\ChargeStatus;
use App\Jobs\AlertExpiringCardsBeforeChargeJob;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\CheckDomainExpiryJob;
use App\Jobs\CheckMoneyIntegrityJob;
use App\Jobs\CheckSiteContentJob;
use App\Jobs\CheckSiteDnsJob;
use App\Jobs\CheckSiteLayoutJob;
use App\Jobs\CheckSitePluginChangesJob;
use App\Jobs\CheckSiteReputationJob;
use App\Jobs\CheckSlaBreachesJob;
use App\Jobs\CheckSslExpiryJob;
use App\Jobs\CheckStoreSalesJob;
use App\Jobs\CheckWhatsappInboundJob;
use App\Jobs\DrillBackupJob;
use App\Jobs\FollowUpPendingTicketsJob;
use App\Jobs\HeartbeatJob;
use App\Jobs\MonitorSiteJob;
use App\Jobs\ReconcileChargeJob;
use App\Jobs\RefreshCloudflareCountryRulesJob;
use App\Jobs\RemindExpiringLicensesJob;
use App\Jobs\RequestMissingCardJob;
use App\Jobs\RunBackupJob;
use App\Jobs\ScanSiteComplianceJob;
use App\Jobs\ScanSiteOpportunitiesJob;
use App\Jobs\ScanSiteVulnerabilitiesJob;
use App\Jobs\SendBroadcastJob;
use App\Jobs\SendDemandRemindersJob;
use App\Jobs\SendProactiveRemindersJob;
use App\Jobs\SendTaskRemindersJob;
use App\Jobs\SyncPluginReleasesJob;
use App\Jobs\WeeklyMaintenanceJob;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\Charge;
use App\Models\HealthHeartbeat;
use App\Models\MonitorCheck;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Models\WebhookEvent;
use App\Providers\SettingsServiceProvider;
use App\Services\Backup\BackupRunner;
use App\Services\Calendar\ShabbatClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
 | All background work is dispatched from here as queued jobs — the scheduler
 | itself only enqueues (§1). Run with `php artisan schedule:work` (dev) or a
 | single system cron entry (prod).
 */

// The scheduler's own locks go through the database, not the default cache.
// In production that cache is Redis, and Redis is also the queue: a Redis
// outage would stop ->onOneServer() from ever handing control to the callback,
// so the very code meant to notice and report the outage would never run. The
// database is already required for anything here to mean anything.
Schedule::useCache('database');

// Outward automations pause over Shabbat and Yom Tov. Each job also rechecks
// the clock in handle() (PausesForShabbat) and re-queues itself for the day
// after — so a daily job whose time falls in the quiet window is HELD, not
// dropped, and a job dispatched just before candle lighting can't slip through.
// The high-frequency dispatchers additionally gate on ->when($awake) to avoid
// piling up redundant deferred jobs each tick. Monitoring and internal safety
// jobs keep running.
$awake = fn (): bool => ! app(ShabbatClock::class)->isBlocked();

/*
 | Proof of life. Both stamps are read by /health, which an EXTERNAL monitor
 | asks — the scheduler cannot report that the scheduler has stopped, and a
 | queue with no worker accepts jobs happily and runs none of them. The first
 | stamp says the scheduler ticked; the second is written by a job, so it says
 | a worker actually ran something. Neither is gated on Shabbat: a system that
 | stops reporting for a day is a system nobody trusts on Sunday.
 */
Schedule::call(fn () => HealthHeartbeat::beat(HealthHeartbeat::SCHEDULER))
    ->everyMinute()->name('system:heartbeat')->onOneServer();

Schedule::job(new HeartbeatJob)->everyFiveMinutes()->name('system:queue-heartbeat')->onOneServer();

// And a second one on the ORDINARY queue. The beat above rides a queue of its
// own so a busy worker is never mistaken for a dead one — which means it can
// keep answering cheerfully while the process that runs charges and invoices is
// gone. Only a job that queued where the real work queues can say otherwise.
Schedule::job(new HeartbeatJob(HealthHeartbeat::WORKLOAD))->everyFiveMinutes()
    ->name('system:workload-heartbeat')->onOneServer();

// Does the money still add up? Reads only — every finding is reported for a
// person to decide on, because automatic repair of money is how one wrong
// assumption becomes a second charge on somebody's card. Silent when clean.
Schedule::job(new CheckMoneyIntegrityJob)->dailyAt('08:15')
    ->name('billing:money-integrity')->onOneServer();

// The newest archive is fetched and read through. Everything else about backups
// reports on the WRITE — the run finished, the upload succeeded — and none of
// that is the question anybody has on the day it matters. Nothing is restored;
// see BackupDrill.
//
// Asked DAILY, and the job itself decides whether enough time has passed. A
// monthly schedule fires in exactly one minute per month: a container restarting
// at 04:30 on the 1st, a deploy, a host reboot — any of them costs the whole
// month, silently, and the next chance is thirty days away. This is how a live
// system reached August with no drill ever recorded. Daily, the same mishap
// costs a day.
Schedule::job(new DrillBackupJob)->dailyAt('04:30')
    ->name('backup:drill')->onOneServer();

// Billing: enqueue a charge for every subscription that is due. The job holds
// a per-subscription lock and re-checks the due date, so double dispatch is safe.
Schedule::call(function () {
    Subscription::query()
        ->dueForCharge()
        ->pluck('id')
        ->each(fn (int $id) => ChargeSubscriptionJob::dispatch($id));
})->everyFifteenMinutes()->name('billing:dispatch-due-charges')->when($awake)->onOneServer();

// Reconcile manual charges left "pending": if Cardcom actually charged the card
// but we never recorded the result (lost webhook / crashed job), finalise the
// charge and issue its invoice. Cardcom is the source of truth; a card is never
// re-charged. Covers saved-token and hosted (walk-in) charges/demands. A bounded
// age window gives the webhook a moment first and stops chasing an abandoned
// (e.g. never-paid) demand forever.
Schedule::call(function () {
    Charge::query()
        ->where('status', ChargeStatus::Pending)
        ->whereNull('subscription_id')       // manual/one-off charges only
        ->whereNotNull('customer_id')
        ->where('created_at', '<=', now()->subMinutes((int) config('billing.cardcom.reconcile_after_minutes', 15)))
        ->where('created_at', '>=', now()->subDays((int) config('billing.cardcom.reconcile_max_age_days', 14)))
        ->pluck('id')
        ->each(fn (int $id) => ReconcileChargeJob::dispatch($id));
})->everyThreeMinutes()->name('billing:reconcile-pending-charges')->onOneServer();

// Uptime monitoring.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => MonitorSiteJob::dispatch($id));
})->cron('*/'.(int) config('billing.monitoring.interval_minutes').' * * * *')
    ->name('monitoring:dispatch-checks')->onOneServer();

// Daily TLS-certificate expiry check for every monitored site.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => CheckSslExpiryJob::dispatch($id));
})->dailyAt('07:00')->name('monitoring:ssl-expiry')->onOneServer();

// Daily domain-registration expiry check (RDAP) for every monitored site.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => CheckDomainExpiryJob::dispatch($id));
})->dailyAt('07:15')->name('monitoring:domain-expiry')->onOneServer();

// Daily plugin/theme-change watch for every connected site: alert the team when
// a new plugin/theme appears. Gated on $awake to stay quiet over Shabbat/Yom Tov.
Schedule::call(function () {
    Site::query()
        ->where('mcp_enabled', true)
        ->whereNotNull('mcp_endpoint')
        ->pluck('id')
        ->each(fn (int $id) => CheckSitePluginChangesJob::dispatch($id));
})->dailyAt('07:30')->name('monitoring:plugin-changes')->onOneServer();

// Silent-failure watch for stores: a shop that answers 200 all day but stopped
// taking orders (broken checkout) or stopped being paid (broken gateway).
// Morning run, so a night-time breakage is caught before the business day.
Schedule::call(function () {
    if (! config('billing.monitoring.store_pulse.enabled', true)) {
        return;
    }

    Site::query()
        ->where('mcp_enabled', true)
        ->whereNotNull('mcp_endpoint')
        ->pluck('id')
        ->each(fn (int $id) => CheckStoreSalesJob::dispatch($id));
})->dailyAt('08:10')->name('monitoring:store-pulse')->onOneServer();

// Layout regression watch: the homepage still answers 200, but the header/menu
// vanished or most images stopped rendering after an update.
Schedule::call(function () {
    if (! config('security.layout.enabled', true)) {
        return;
    }

    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => CheckSiteLayoutJob::dispatch($id));
})->dailyAt('08:25')->name('monitoring:layout-watch')->onOneServer();

// Weekly accessibility + legal-documents audit for every site with a domain.
// Thursday morning, with the other two site sweeps — the three read each other's
// findings and are kept on the same morning deliberately.
Schedule::call(function () {
    if (! config('security.compliance.enabled', true)) {
        return;
    }

    Site::query()
        ->whereNotNull('domain')
        ->pluck('id')
        ->each(fn (int $id) => ScanSiteComplianceJob::dispatch($id));
})->weeklyOn(4, '05:40')->name('monitoring:compliance-scan')->onOneServer();

// Weekly opportunity sweep: turn what we already know about every site into a
// priced list of work worth offering (accessibility, legal docs, speed, broken
// links, SEO basics, old PHP). Runs half an hour after the compliance scan, on
// the same morning, so it reads findings taken today rather than last week's.
Schedule::call(function () {
    if (! config('growth.opportunities.enabled', true)) {
        return;
    }

    Site::query()
        ->whereNotNull('domain')
        ->pluck('id')
        ->each(fn (int $id) => ScanSiteOpportunitiesJob::dispatch($id));
})->weeklyOn(4, '06:10')->name('growth:opportunity-radar')->onOneServer();

// Weekly proactive maintenance: propose (or auto-run under a standing
// approval) plugin updates for every connected site, with a homepage health
// check after each update. Thursday morning, with the other two site sweeps.
// Note the trade-off of that day: an update that breaks a site is found with
// the weekend ahead, so the homepage check after each update is what stands
// between a bad update and two quiet days. Internal proposal, not a customer
// message — no Shabbat gate.
Schedule::call(function () {
    if (! config('agent.weekly_maintenance', true)) {
        return;
    }

    Site::query()
        ->where('mcp_enabled', true)
        ->whereNotNull('mcp_endpoint')
        ->pluck('id')
        ->each(fn (int $id) => WeeklyMaintenanceJob::dispatch($id));
})->weeklyOn(4, '06:30')->name('maintenance:weekly-plugin-updates')->onOneServer();

// Daily security scan for every connected site: match installed plugins/themes/
// core against the vulnerability feed and alert on newly-found issues. Gated on
// $awake so it stays quiet over Shabbat/Yom Tov like the other site dispatchers.
Schedule::call(function () {
    if (! config('security.vulnerabilities.enabled', true)) {
        return;
    }

    Site::query()
        ->where('mcp_enabled', true)
        ->whereNotNull('mcp_endpoint')
        ->pluck('id')
        ->each(fn (int $id) => ScanSiteVulnerabilitiesJob::dispatch($id));
})->dailyAt('07:45')->name('monitoring:vulnerability-scan')->onOneServer();

// Daily domain-reputation check for every site with a domain: flag it if it
// appears on a public spam/malware blocklist. External (no site connection
// needed); gated on $awake to stay quiet over Shabbat/Yom Tov.
Schedule::call(function () {
    if (! config('security.reputation.enabled', true)) {
        return;
    }

    Site::query()
        ->whereNotNull('domain')
        ->where('domain', '!=', '')
        ->pluck('id')
        ->each(fn (int $id) => CheckSiteReputationJob::dispatch($id));
})->dailyAt('07:50')->name('monitoring:reputation-check')->onOneServer();

// Daily DNS-change watch: snapshot each monitored domain's A/MX/NS records and
// alert when they differ from yesterday — hijack/silent-migration detection.
Schedule::call(function () {
    if (! config('security.dns_watch.enabled', true)) {
        return;
    }

    Site::query()
        ->whereNotNull('domain')
        ->where('domain', '!=', '')
        ->pluck('id')
        ->each(fn (int $id) => CheckSiteDnsJob::dispatch($id));
})->dailyAt('07:55')->name('monitoring:dns-watch')->onOneServer();

// Daily defacement watch: fingerprint each monitored homepage and alert when
// the content changed drastically vs the baseline (hack/injection detection).
Schedule::call(function () {
    if (! config('security.defacement.enabled', true)) {
        return;
    }

    Site::query()
        ->where('monitor_enabled', true)
        ->whereNotNull('domain')
        ->where('domain', '!=', '')
        ->pluck('id')
        ->each(fn (int $id) => CheckSiteContentJob::dispatch($id));
})->dailyAt('08:05')->name('monitoring:defacement-watch')->onOneServer();

// Read the Cloudflare country rules into the panel's snapshot. Two API calls per
// zone is far too much for the request that opens the window — it used to spin
// and then die — so the reading happens here, and the window shows the last one
// with its age. Hourly: the rules change by hand, rarely, and every change from
// the panel refreshes this itself.
Schedule::job(new RefreshCloudflareCountryRulesJob)
    ->hourly()->name('cloudflare:country-rules-snapshot')->onOneServer();

// New releases published on our plugins' development repositories. Hourly, so a
// tagged release is available to publish within the hour — but never published
// by itself: sending a build to every customer's shop stays a decision somebody
// makes in front of a screen that says what it costs.
Schedule::job(new SyncPluginReleasesJob)
    ->hourly()->name('licensing:sync-releases')->onOneServer();

// Plugin licences approaching their expiry date. A licence that lapses quietly
// does not look like an expiry to the customer — it looks like the plugin
// stopped updating, and the support ticket arrives instead of the renewal.
// Only licences with no subscription behind them: the rest are chased by the
// billing machine already, and two alerts for one problem train people to read
// neither.
Schedule::job(new RemindExpiringLicensesJob)
    ->dailyAt('08:20')->name('licensing:expiry-reminders')->when($awake)->onOneServer();

// Proactive reminders: a once-a-day internal digest (renewals due, cards
// expiring, open debt) so the owner can act before anything slips.
Schedule::job(new SendProactiveRemindersJob)
    ->dailyAt('08:00')->name('reminders:daily-digest')->onOneServer();

// Catch cards that will expire before their subscription's next charge (a
// doomed auto-charge): invite the customer to update their card ahead of time
// and flag the team, once per card. Timing window in config/billing.php.
Schedule::job(new AlertExpiringCardsBeforeChargeJob)
    ->dailyAt('08:15')->name('billing:card-expiry-before-charge')->onOneServer();

// Chase tickets stuck "waiting for customer": remind once after reminder_days
// of silence, then auto-close after close_days. Timings in config/billing.php.
Schedule::job(new FollowUpPendingTicketsJob)
    ->dailyAt('09:00')->name('support:pending-followup')->onOneServer();

// Alert the team about open tickets that breached their first-response SLA
// (once per ticket). Hourly so a breach surfaces the same working hour; gated
// on $awake so it stays quiet over Shabbat / Yom Tov like the other dispatchers.
Schedule::job(new CheckSlaBreachesJob)
    ->hourly()->name('support:sla-breaches')->when($awake)->onOneServer();

// Watch the inbound WhatsApp path. A broken one has no symptom — outbound keeps
// working and the ticket queue just stops filling, which looks exactly like a
// quiet day. Alerts only on a definite fault (nothing registered, registered
// elsewhere, never delivered), never on a merely quiet week.
Schedule::job(new CheckWhatsappInboundJob)
    ->hourly()->name('support:whatsapp-inbound-health')->onOneServer();

// Ask for a card when the charge date passed and none is on file. Nothing else
// would: no charge is attempted without a token, so no failure exists, so the
// dunning machine — which chases failures — never starts, and the customer is
// never told their subscription is unpaid. Gated on $awake like every other
// outward automation.
Schedule::job(new RequestMissingCardJob)
    ->dailyAt('09:30')->name('billing:request-missing-cards')->when($awake)->onOneServer();

// Chase unpaid payment demands: after the quiet interval, resend the request
// (link/transfer) up to the configured maximum, then stop.
Schedule::job(new SendDemandRemindersJob)
    ->dailyAt('10:00')->name('billing:demand-reminders')->onOneServer();

// Daily task reminders: email each team member their open tasks due today or
// overdue (once per task; the clock resets on reschedule/reopen).
Schedule::job(new SendTaskRemindersJob)
    ->dailyAt((string) config('billing.support.task_reminders.time', '08:30'))
    ->name('support:task-reminders')->onOneServer();

// Scheduled broadcasts.
Schedule::call(function () {
    Broadcast::query()
        ->where('status', BroadcastStatus::Scheduled)
        ->where('scheduled_at', '<=', now())
        ->pluck('id')
        ->each(fn (int $id) => SendBroadcastJob::dispatch($id));
})->everyFiveMinutes()->name('broadcasts:dispatch-scheduled')->when($awake)->onOneServer();

// Nightly backup to the external destination. Deliberately NOT gated on
// $awake: a backup sends nothing outward and touches no customer, so pausing it
// over Shabbat would only mean a day of the year with no copy of the business.
// The job holds a lock, so a slow run can never be started twice.
//
// Checked every minute rather than declared with dailyAt(): the schedule is
// built once, and `schedule:work` is a long-lived process — a time changed in
// the panel would otherwise be ignored until someone restarted the scheduler,
// which is not a thing anyone would think to do. The settings overlay is
// re-applied here because a Schedule::call closure never passes through
// Queue::before.
Schedule::call(function () {
    SettingsServiceProvider::refreshFromDatabase();

    RunBackupJob::dispatchNightly();
})->everyMinute()
    ->when(function (): bool {
        SettingsServiceProvider::refreshFromDatabase();

        return now()->format('H:i') === trim((string) config('backup.daily_at', '03:30'));
    })
    ->name('system:daily-backup')->onOneServer();

// And a look each morning at whether the backup actually happened. A queue that
// accepted the job but has no worker to run it leaves no failed row at all —
// the silence looks exactly like a healthy night, until the day somebody needs
// the archive.
//
// It cannot report a scheduler that has stopped, because it IS the scheduler.
// That case is covered from the other side: the backup screen asks the same
// question on every page load, and an external uptime monitor is the only real
// answer (see docs/deployment.md).
Schedule::call(function () {
    SettingsServiceProvider::refreshFromDatabase();

    app(BackupRunner::class)->alertIfStale();
})->dailyAt('09:00')->name('system:backup-stale-check')->onOneServer();

// Horizon metrics snapshot.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Prune the in-panel system log ("מערכת ועדכונים") so it self-cleans.
// NOTE: each prune re-applies the settings overlay first. These are Schedule::call
// closures, which — unlike queued jobs — never pass through Queue::before, so in a
// long-lived scheduler (schedule:work) a retention window just changed in the panel
// would otherwise be ignored until the container restarts.
Schedule::call(function () {
    SettingsServiceProvider::refreshFromDatabase();
    SystemLog::prune((int) config('billing.system.log_retention_days', 30));
})->dailyAt('03:00')->name('system:prune-logs')->onOneServer();

// Prune uptime-probe history — the fastest-growing table in the system (one row
// per probe per site). Retention stays longer than the monthly-report window.
Schedule::call(function () {
    SettingsServiceProvider::refreshFromDatabase();
    MonitorCheck::query()
        ->where('checked_at', '<', now()->subDays((int) config('billing.system.monitor_check_retention_days', 90)))
        ->delete();
})->dailyAt('03:10')->name('system:prune-monitor-checks')->onOneServer();

// Prune inbound-webhook audit rows; idempotency only needs a short window.
Schedule::call(function () {
    SettingsServiceProvider::refreshFromDatabase();
    WebhookEvent::query()
        ->where('created_at', '<', now()->subDays((int) config('billing.system.webhook_retention_days', 60)))
        ->delete();
})->dailyAt('03:20')->name('system:prune-webhook-events')->onOneServer();

// Prune read in-panel notifications so the notifications table stays small.
Schedule::call(function () {
    SettingsServiceProvider::refreshFromDatabase();
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('created_at', '<', now()->subDays((int) config('billing.system.notification_retention_days', 30)))
        ->delete();
})->dailyAt('03:30')->name('system:prune-notifications')->onOneServer();

// Prune the team-action audit log after its (long) retention window.
Schedule::call(function () {
    AuditLog::query()
        ->where('created_at', '<', now()->subDays((int) config('security.audit.retention_days', 365)))
        ->delete();
})->dailyAt('03:40')->name('system:prune-audit-logs')->onOneServer();
