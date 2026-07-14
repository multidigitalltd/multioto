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
use App\Services\Billing\ManualChargeService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeReconciler;
use App\Services\Linet\InvoiceIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualChargeTest extends TestCase
{
    use RefreshDatabase;

    private function oneOffCharge(Customer $customer, ChargeStatus $status, ?string $notes = null): Charge
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
            'invoice_notes' => $notes,
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

        // The charge hit Cardcom with the saved token and a Document — our
        // terminal mandates one (5046), and 'Auto' lets Cardcom pick a supported
        // type. The card number never leaves Cardcom.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'Transactions/Transaction')
            && ($request->data()['Token'] ?? null) === $token->cardcom_token
            && ($request->data()['ExternalUniqTranId'] ?? null) === "manual-{$charge->id}"
            && ($request->data()['Document']['DocumentTypeToCreate'] ?? null) === 'Auto');
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
        // The terminal mandates a document (5046); 'Auto' is the default type.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'LowProfile/Create')
            && ($request->data()['Operation'] ?? null) === 'ChargeOnly'
            && ($request->data()['Amount'] ?? null) == 118.0
            && ($request->data()['ReturnValue'] ?? null) === 'charge:42'
            && ($request->data()['Document']['DocumentTypeToCreate'] ?? null) === 'Auto');
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

        (new IssueInvoiceJob($charge->id))->handle(app(InvoiceIssuer::class));

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

    public function test_invoice_notes_are_printed_on_the_linet_line(): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid', 'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1', 'billing.linet.doctype' => '9',
            'billing.linet.vat_cat_taxable' => 1, 'billing.linet.payment_type' => 3,
        ]);
        Http::fake(['*/create/doc' => Http::response(['id' => 777])]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded, notes: 'כולל התקנת תוסף SEO ותקופת הרצה');

        (new IssueInvoiceJob($charge->id))->handle(app(InvoiceIssuer::class));

        // The line name stays the short description; the operator's note rides
        // along on the line's description sub-field (a known-accepted field).
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create/doc')
            && $request->data()['docDet'][0]['name'] === 'שירות חד-פעמי'
            && $request->data()['docDet'][0]['description'] === 'כולל התקנת תוסף SEO ותקופת הרצה');
    }

    public function test_a_multi_line_charge_issues_a_linet_document_with_one_line_per_item(): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid', 'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1', 'billing.linet.doctype' => '9',
            'billing.linet.vat_cat_taxable' => 1, 'billing.linet.payment_type' => 3,
        ]);
        Http::fake(['*/create/doc' => Http::response(['id' => 888])]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => 25424,
            'vat_agorot' => 4576,
            'total_agorot' => 30000, // 2×10000 + 1×10000
            'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'description' => 'חבילה',
            'lines' => [
                ['name' => 'אחסון שנתי', 'qty' => 2, 'unit_price_agorot' => 10000],
                ['name' => 'תוסף SEO', 'qty' => 1, 'unit_price_agorot' => 10000],
            ],
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        (new IssueInvoiceJob($charge->id))->handle(app(InvoiceIssuer::class));

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/create/doc')) {
                return false;
            }
            $det = $request->data()['docDet'];

            return count($det) === 2
                && $det[0]['name'] === 'אחסון שנתי' && $det[0]['qty'] === 2 && $det[0]['iItem'] === 100.0 && $det[0]['line'] === 1
                && $det[1]['name'] === 'תוסף SEO' && $det[1]['qty'] === 1 && $det[1]['iItem'] === 100.0 && $det[1]['line'] === 2
                // Payment (docCheq) still totals the whole charge.
                && $request->data()['docCheq'][0]['sum'] === 300.0;
        });
    }

    public function test_invoice_lines_fall_back_to_a_single_line_from_the_charge_total(): void
    {
        $customer = Customer::factory()->create();
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded);

        $lines = $charge->invoiceLines();

        $this->assertCount(1, $lines);
        $this->assertSame('שירות חד-פעמי', $lines[0]['name']);
        $this->assertSame(11800, $lines[0]['unit_price_agorot']);
    }

    public function test_manual_charge_service_persists_invoice_notes_on_the_charge(): void
    {
        Bus::fake([IssueInvoiceJob::class, ProcessManualChargeJob::class]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = app(ManualChargeService::class)->chargeSavedToken($customer, 11800, 'שירות', 'הערה חשובה');

        $this->assertSame('הערה חשובה', $charge->invoice_notes);
    }

    public function test_manual_charge_service_splits_vat_and_queues_the_charge(): void
    {
        Bus::fake([IssueInvoiceJob::class, ProcessManualChargeJob::class]);
        config(['billing.vat_rate' => 0.18]);

        $service = app(ManualChargeService::class);

        // VAT-inclusive 118 → net 100 + VAT 18 for a taxable customer.
        $taxable = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $service->chargeSavedToken($taxable, 11800, 'שירות');
        $this->assertSame(10000, $charge->amount_agorot);
        $this->assertSame(1800, $charge->vat_agorot);
        $this->assertSame(11800, $charge->total_agorot);
        $this->assertNull($charge->subscription_id);
        Bus::assertDispatched(ProcessManualChargeJob::class);

        // Exempt customer: the whole amount is net, no VAT.
        $exempt = Customer::factory()->create(['vat_exempt' => true]);
        [$net, $vat] = $service->splitVat(11800, true);
        $this->assertSame(11800, $net);
        $this->assertSame(0, $vat);
    }

    public function test_invoice_issuer_names_the_missing_linet_settings_before_calling_linet(): void
    {
        // No doctype configured — the exact scenario where Linet answers with a
        // cryptic "invalid doctype". The issuer must name what's missing in
        // Hebrew and never reach the network.
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid',
            'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1',
            'billing.linet.doctype' => null,
        ]);
        Http::fake();

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded);

        $result = app(InvoiceIssuer::class)->issue($charge);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('קוד סוג מסמך', $result['error']);
        Http::assertNothingSent();
    }

    public function test_issue_is_a_no_op_when_the_charge_already_has_an_invoice(): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid', 'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1', 'billing.linet.doctype' => '9',
            'billing.linet.vat_cat_taxable' => 1, 'billing.linet.payment_type' => 3,
        ]);
        Http::fake();

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded);
        $charge->invoice()->create([
            'customer_id' => $customer->id,
            'linet_document_id' => '111',
            'amount_agorot' => $charge->amount_agorot,
            'vat_agorot' => $charge->vat_agorot,
            'total_agorot' => $charge->total_agorot,
            'issued_at' => now(),
        ]);

        $result = app(InvoiceIssuer::class)->issue($charge->fresh());

        // Already issued → success no-op, and Linet is never called again.
        $this->assertTrue($result['ok']);
        Http::assertNothingSent();
        $this->assertSame(1, $charge->invoice()->count());
    }

    public function test_issue_never_calls_linet_twice_while_another_issue_holds_the_lock(): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid', 'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1', 'billing.linet.doctype' => '9',
            'billing.linet.vat_cat_taxable' => 1, 'billing.linet.payment_type' => 3,
        ]);
        Http::fake(['*/create/doc' => Http::response(['id' => 999])]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded);

        // Simulate a concurrent issue already in flight (async job racing the
        // manual button, or a double-click): the lock is held elsewhere.
        $lock = Cache::lock("invoice-issue:{$charge->id}", 120);
        $this->assertTrue($lock->get());

        try {
            $result = app(InvoiceIssuer::class)->issue($charge);
        } finally {
            $lock->release();
        }

        // Treated as a success no-op — and crucially, no second Linet document.
        $this->assertTrue($result['ok']);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('invoices', ['charge_id' => $charge->id]);
    }

    public function test_invoice_issuer_returns_the_linet_error_instead_of_failing_silently(): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid',
            'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1',
            'billing.linet.doctype' => '9',
        ]);
        Http::fake(['*/create/doc' => Http::response(['error' => 'invalid doctype'], 500)]);

        $customer = Customer::factory()->create();
        $charge = $this->oneOffCharge($customer, ChargeStatus::Succeeded);

        $result = app(InvoiceIssuer::class)->issue($charge);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
        $this->assertDatabaseMissing('invoices', ['charge_id' => $charge->id]);
    }
}
