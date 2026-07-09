<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Enums\WebhookSource;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\WebhookEvent;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeReconciler;
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
            && ($request->data()['ExternalUniqTranId'] ?? null) === "manual-{$charge->id}"
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

    public function test_manual_charge_ignores_a_replaced_token(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        $customer = Customer::factory()->create();
        // Only a replaced (superseded) token exists — it must never be charged.
        PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Replaced]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Pending);

        (new ProcessManualChargeJob($charge->id))->handle(app(CardcomClient::class));

        $this->assertSame(ChargeStatus::Failed, $charge->refresh()->status);
        Bus::assertNotDispatched(IssueInvoiceJob::class);
    }

    public function test_hosted_charge_low_profile_is_built_with_charge_only_and_a_document(): void
    {
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/lp', 'LowProfileId' => 'LP999'])]);

        $result = app(CardcomClient::class)->createChargeLowProfile(42, 11800, 'שירות חד-פעמי', 'דני', 'a@b.co', '+97250', 's', 'f', 'w');

        $this->assertSame('LP999', $result['low_profile_id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'LowProfile/Create')
            && ($request->data()['Operation'] ?? null) === 'ChargeOnly'
            && ($request->data()['Amount'] ?? null) == 118.0
            && ($request->data()['ReturnValue'] ?? null) === 'charge:42'
            && ($request->data()['Document']['DocumentTypeToCreate'] ?? null) === 'Order');
    }

    public function test_hosted_walk_in_charge_is_finalised_by_the_webhook(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);

        $customer = Customer::factory()->create();
        $charge = Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'חיוב חד-פעמי',
            'cardcom_low_profile_id' => 'LP123',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0,
            'TranzactionId' => 555,
            'TokenInfo' => ['Token' => 'tok_abc', 'CardLast4Digits' => '1234'],
        ])]);

        [$event] = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile_completed', 'LP123', ['LowProfileId' => 'LP123']);

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $charge->refresh();
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('555', $charge->cardcom_transaction_id);
        Bus::assertDispatched(IssueInvoiceJob::class);
        // The captured card is stored so the walk-in becomes reusable.
        $this->assertDatabaseHas('payment_tokens', ['customer_id' => $customer->id, 'cardcom_token' => 'tok_abc']);
    }

    public function test_reconcile_finalises_a_stuck_saved_token_charge_via_external_id(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);

        $customer = Customer::factory()->create();
        $charge = $this->oneOffCharge($customer, ChargeStatus::Pending); // no low_profile_id → saved-token path

        // Cardcom confirms the charge went through even though we never recorded it.
        Http::fake(['*/Transactions/GetTransactionByExternalUniqTran' => Http::response(['ResponseCode' => 0, 'TranzactionId' => 424242])]);

        $status = app(ChargeReconciler::class)->reconcile($charge);

        $this->assertSame('succeeded', $status);
        $charge->refresh();
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('424242', $charge->cardcom_transaction_id);
        Bus::assertDispatched(IssueInvoiceJob::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'GetTransactionByExternalUniqTran')
            && ($request->data()['ExternalUniqTranId'] ?? null) === "manual-{$charge->id}");
    }

    public function test_reconcile_finalises_a_stuck_hosted_charge_via_low_profile(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);

        $customer = Customer::factory()->create();
        $charge = $this->oneOffCharge($customer, ChargeStatus::Pending);
        $charge->update(['cardcom_low_profile_id' => 'LP-77']);

        Http::fake(['*/LowProfile/GetLpResult' => Http::response(['ResponseCode' => 0, 'TranzactionId' => 99])]);

        $status = app(ChargeReconciler::class)->reconcile($charge);

        $this->assertSame('succeeded', $status);
        $this->assertSame(ChargeStatus::Succeeded, $charge->refresh()->status);
        Bus::assertDispatched(IssueInvoiceJob::class);
    }

    public function test_reconcile_leaves_pending_when_cardcom_has_no_matching_charge(): void
    {
        Bus::fake([IssueInvoiceJob::class]);
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);

        $customer = Customer::factory()->create();
        $charge = $this->oneOffCharge($customer, ChargeStatus::Pending);

        // Not found / not charged → non-zero code. Must NOT be marked failed (no re-charge guesswork).
        Http::fake(['*/Transactions/GetTransactionByExternalUniqTran' => Http::response(['ResponseCode' => 33, 'Description' => 'not found'])]);

        $status = app(ChargeReconciler::class)->reconcile($charge);

        $this->assertSame('pending', $status);
        $this->assertSame(ChargeStatus::Pending, $charge->refresh()->status);
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
