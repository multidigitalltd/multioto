<?php

namespace App\Jobs;

use App\Mail\MonitoringReportMail;
use App\Models\Customer;
use App\Services\Automation\ApprovalGate;
use App\Services\Monitoring\MonitoringReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Email the customer their monthly monitoring report — dispatched when a
 * subscription is charged (their billing day). Sent at most once per calendar
 * month. A GOOD month (every site met the uptime threshold) is emailed
 * automatically; a bad month is routed through the approval gate so the team
 * reviews it before it reaches the customer — no embarrassment over an outage.
 */
class SendMonthlyMonitoringReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $customerId) {}

    public function handle(MonitoringReport $reports, ApprovalGate $gate): void
    {
        if (! config('billing.monitoring.monthly_report.enabled')) {
            return;
        }

        $customer = Customer::find($this->customerId);

        if (! $customer || blank($customer->email)) {
            return;
        }

        // Once per calendar month, even if charged more than once.
        if ($customer->monitoring_report_sent_at?->isSameMonth(now())) {
            return;
        }

        $report = $reports->for($customer);

        if ($report['sites'] === []) {
            return; // Nothing monitored — no report to send.
        }

        $threshold = (float) config('billing.monitoring.monthly_report.auto_uptime_threshold', 99.9);
        $minUptime = $report['min_uptime'];

        // Mark this month handled up-front so a retry / a second charge can't
        // re-send or re-propose the same report.
        $customer->update(['monitoring_report_sent_at' => now()]);

        if ($minUptime === null || $minUptime >= $threshold) {
            Mail::to($customer->email)->send(new MonitoringReportMail($customer, $report));

            return;
        }

        // Below the auto-send bar — hold for a human to approve before sending.
        $gate->propose(
            type: 'monitoring_report',
            summary: sprintf(
                "דוח ניטור חודשי ל%s ממתין לאישור לפני שליחה ללקוח.\nהזמינות המינימלית החודש: %.2f%% (סף לשליחה אוטומטית: %.1f%%).",
                $customer->name,
                $minUptime,
                $threshold,
            ),
            payload: ['customer_id' => $customer->id],
            customerId: $customer->id,
        );
    }
}
