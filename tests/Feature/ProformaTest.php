<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\IssueProformaJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Linet\ProformaIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProformaTest extends TestCase
{
    use RefreshDatabase;

    private function linetConfig(bool $withProforma = true): void
    {
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid',
            'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1',
            'billing.linet.doctype' => '9',
            'billing.linet.doctype_proforma' => $withProforma ? '30' : null,
            'billing.linet.vat_cat_taxable' => 1,
            'billing.linet.vat_cat_exempt' => 2,
            'billing.linet.payment_type' => 3,
        ]);
    }

    private function demand(Customer $customer): Charge
    {
        return Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'דרישת תשלום',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
    }

    public function test_it_issues_a_proforma_document_with_no_payment_line(): void
    {
        $this->linetConfig();
        Http::fake(['*/create/doc' => Http::response(['id' => 4321, 'pdf' => 'https://app.linet.test/doc/4321.pdf'])]);

        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $charge = $this->demand($customer);

        (new IssueProformaJob($charge->id))->handle(app(ProformaIssuer::class));

        $charge->refresh();
        $this->assertSame('4321', $charge->proforma_document_id);
        $this->assertSame('https://app.linet.test/doc/4321.pdf', $charge->proforma_pdf_url);

        // A proforma is a demand, not a receipt: it carries the line items but
        // NO docCheq (nothing has been paid), and uses the proforma doc type.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create/doc')
            && $request->data()['doctype'] === '30'
            && ! array_key_exists('docCheq', $request->data())
            && $request->data()['docDet'][0]['name'] === 'דרישת תשלום');
    }

    public function test_it_is_a_no_op_when_no_proforma_document_type_is_configured(): void
    {
        $this->linetConfig(withProforma: false);
        Http::fake();

        $customer = Customer::factory()->create();
        $charge = $this->demand($customer);

        (new IssueProformaJob($charge->id))->handle(app(ProformaIssuer::class));

        // No document type → the demand still goes out, but no Linet call.
        Http::assertNothingSent();
        $this->assertNull($charge->refresh()->proforma_document_id);
    }

    public function test_it_does_not_issue_a_second_proforma_for_the_same_demand(): void
    {
        $this->linetConfig();
        Http::fake(['*/create/doc' => Http::response(['id' => 777])]);

        $customer = Customer::factory()->create();
        $charge = $this->demand($customer);
        $charge->update(['proforma_document_id' => '777']);

        (new IssueProformaJob($charge->id))->handle(app(ProformaIssuer::class));

        // Already issued → success no-op, Linet is never called again.
        Http::assertNothingSent();
    }
}
