<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\Ai\SupportToolkit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportToolkitTest extends TestCase
{
    use RefreshDatabase;

    public function test_facts_include_account_site_and_invoice_data(): void
    {
        $customer = Customer::factory()->create();
        $plan = Plan::factory()->create(['name' => 'אחזקה עסקית']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);
        Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'shop.co.il']);
        $charge = Charge::create([
            'subscription_id' => $subscription->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);
        Invoice::create([
            'charge_id' => $charge->id,
            'customer_id' => $customer->id,
            'linet_document_id' => 'DOC-7',
            'document_type' => 'tax_invoice_receipt',
            'vat_category' => 'taxable',
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'issued_at' => now(),
        ]);

        $facts = app(SupportToolkit::class)->factsFor($customer);

        $this->assertStringContainsString('אחזקה עסקית', $facts);
        $this->assertStringContainsString('shop.co.il', $facts);
        $this->assertStringContainsString('DOC-7', $facts);
        // The card-update link is a signed URL to the update-card route.
        $this->assertStringContainsString('/billing/update-card/', $facts);
        $this->assertStringContainsString('signature=', $facts);
    }

    public function test_site_status_reports_an_open_incident_as_down(): void
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'down.co.il']);
        Incident::create([
            'site_id' => $site->id,
            'started_at' => now()->subHour(),
            'status' => IncidentStatus::Open,
        ]);

        $lines = app(SupportToolkit::class)->siteStatus($customer);

        $this->assertStringContainsString('down.co.il', $lines[0]);
        $this->assertStringContainsString('למטה', $lines[0]);
    }

    public function test_site_status_includes_the_real_last_observed_symptom(): void
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain' => 'broken.co.il',
            'ssl_days_left' => 0,
        ]);
        Incident::create(['site_id' => $site->id, 'started_at' => now()->subHour(), 'status' => IncidentStatus::Open]);
        $site->monitorChecks()->create([
            'checked_at' => now(), 'is_up' => false, 'status_code' => 502,
            'response_ms' => 120, 'error' => 'Bad Gateway',
        ]);

        $line = app(SupportToolkit::class)->siteStatus($customer)[0];

        // The AI now sees the concrete failure, not just "down".
        $this->assertStringContainsString('HTTP 502', $line);
        $this->assertStringContainsString('Bad Gateway', $line);
        $this->assertStringContainsString('תעודת SSL פגה', $line);
    }

    public function test_canceled_subscriptions_are_excluded_from_the_account_summary(): void
    {
        $customer = Customer::factory()->create();
        Subscription::factory()->create(['customer_id' => $customer->id, 'status' => SubscriptionStatus::Canceled]);

        $this->assertSame([], app(SupportToolkit::class)->accountSummary($customer));
    }
}
