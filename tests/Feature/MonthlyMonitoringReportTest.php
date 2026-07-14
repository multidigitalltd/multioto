<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Jobs\SendMonthlyMonitoringReportJob;
use App\Mail\MonitoringReportMail;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Automation\ApprovalGate;
use App\Services\Monitoring\MonitoringReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonthlyMonitoringReportTest extends TestCase
{
    use RefreshDatabase;

    /** Create a monitored site for the customer with `$up` up-checks and `$down` down-checks. */
    private function siteWithChecks(Customer $customer, int $up, int $down): Site
    {
        $site = Site::factory()->create(['customer_id' => $customer->id, 'monitor_enabled' => true]);

        for ($i = 0; $i < $up; $i++) {
            $site->monitorChecks()->create(['checked_at' => now()->subHours($i), 'is_up' => true, 'response_ms' => 200]);
        }
        for ($i = 0; $i < $down; $i++) {
            $site->monitorChecks()->create(['checked_at' => now()->subHours($i), 'is_up' => false, 'response_ms' => null]);
        }

        return $site;
    }

    public function test_report_computes_per_site_uptime(): void
    {
        $customer = Customer::factory()->create();
        $this->siteWithChecks($customer, up: 95, down: 5);

        $report = app(MonitoringReport::class)->for($customer);

        $this->assertCount(1, $report['sites']);
        $this->assertSame(95.0, $report['sites'][0]['uptime']);
        $this->assertSame(95.0, $report['min_uptime']);
    }

    public function test_a_good_month_is_emailed_automatically(): void
    {
        config(['billing.monitoring.monthly_report.enabled' => true]);
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'client@corp.com']);
        $this->siteWithChecks($customer, up: 100, down: 0); // 100% uptime

        SendMonthlyMonitoringReportJob::dispatchSync($customer->id);

        Mail::assertSent(MonitoringReportMail::class);
        $this->assertNotNull($customer->fresh()->monitoring_report_sent_at);
        $this->assertSame(0, PendingAction::where('type', 'monitoring_report')->count());
    }

    public function test_a_bad_month_waits_for_manual_approval(): void
    {
        config([
            'billing.monitoring.monthly_report.enabled' => true,
            'billing.monitoring.monthly_report.auto_uptime_threshold' => 99.9,
        ]);
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'client@corp.com']);
        $this->siteWithChecks($customer, up: 90, down: 10); // 90% — below the bar

        SendMonthlyMonitoringReportJob::dispatchSync($customer->id);

        // NOT emailed — held for approval instead.
        Mail::assertNotSent(MonitoringReportMail::class);
        $action = PendingAction::where('type', 'monitoring_report')->sole();
        $this->assertSame($customer->id, $action->customer_id);

        // Approving it sends the report to the customer.
        app(ApprovalGate::class)->approve($action);
        Mail::assertSent(MonitoringReportMail::class);
        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
    }

    public function test_it_sends_at_most_once_per_month(): void
    {
        config(['billing.monitoring.monthly_report.enabled' => true]);
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'client@corp.com', 'monitoring_report_sent_at' => now()]);
        $this->siteWithChecks($customer, up: 100, down: 0);

        SendMonthlyMonitoringReportJob::dispatchSync($customer->id);

        Mail::assertNothingSent();
    }

    public function test_it_is_a_no_op_when_disabled(): void
    {
        config(['billing.monitoring.monthly_report.enabled' => false]);
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'client@corp.com']);
        $this->siteWithChecks($customer, up: 100, down: 0);

        SendMonthlyMonitoringReportJob::dispatchSync($customer->id);

        Mail::assertNothingSent();
        $this->assertNull($customer->fresh()->monitoring_report_sent_at);
    }
}
