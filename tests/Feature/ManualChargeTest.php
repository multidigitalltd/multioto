<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Services\Cardcom\CardcomClient;
use App\Services\Linet\LinetClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualChargeTest extends TestCase
{
    use RefreshDatabase;

    private function oneOffCharge(Customer $customer, ChargeStatus $status): Charge
    {
        return Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => $status,
            'attempt_number' => 1,
            'description' => 'שירות חד-פעמי',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
    }

    public function test_manual_charge_job_charges_the_saved_token_and_queues_the_invoice(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Pending);

        Http::fake(['*/Transactions/Transaction' => Http::response(['ResponseCode' => 0, 'TranzactionId' => 987654])]);

        (new ProcessManualChargeJob($charge->id))->handle(app(CardcomClient::class));

        $charge->refresh();
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('987654', $charge->cardcom_transaction_id);
        Bus::assertDispatched(IssueInvoiceJob::class);

        // The charge hit Cardcom with the saved token and a Document (terminal
        // requirement); the card number itself never leaves Cardcom.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'Transactions/Transaction')
            && ($request->data()['Token'] ?? null) === $token->cardcom_token
            && ($request->data()['Document']['DocumentTypeToCreate'] ?? null) === 'Order');
    }

    public function test_manual_charge_fails_gracefully_when_the_customer_has_no_token(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        $customer = Customer::factory()->create();
        $charge = $this->oneOffCharge($customer, ChargeStatus::Pending);

        (new ProcessManualChargeJob($charge->id))->handle(app(CardcomClient::class));

        $this->assertSame(ChargeStatus::Failed, $charge->refresh()->status);
        Bus::assertNotDispatched(IssueInvoiceJob::class);
    }

    public function test_issue_invoice_job_issues_a_linet_document_for_a_one_off_charge(): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid',
            'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1',
            'billing.linet.doctype' => '9',
            'billing.linet.vat_cat_taxable' => 1,
            'billing.linet.vat_cat_exempt' => 2,
            'billing.linet.payment_type' => 3,
        ]);
        Http::fake(['*/create/doc' => Http::response(['id' => 555, 'pdf' => 'https://app.linet.test/doc/555.pdf'])]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded);

        (new IssueInvoiceJob($charge->id))->handle(app(LinetClient::class));

        // A one-off charge (no subscription) still issues a Linet invoice, with
        // the customer resolved directly and the charge's own description.
        $this->assertDatabaseHas('invoices', [
            'charge_id' => $charge->id,
            'customer_id' => $customer->id,
            'linet_document_id' => '555',
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create/doc')
            && $request->data()['docDet'][0]['name'] === 'שירות חד-פעמי');
    }
}
