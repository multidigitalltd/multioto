<?php

namespace Tests\Feature;

use App\Enums\VatCategory;
use App\Models\Charge;
use App\Models\Subscription;
use App\Services\Linet\LinetClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinetClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid',
            'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '1',
            'billing.linet.doctype' => '9',
            'billing.linet.vat_cat_taxable' => 1,
            'billing.linet.vat_cat_exempt' => 2,
            'billing.linet.payment_type' => 3,
            'billing.linet.email_document' => true,
        ]);
    }

    private function charge(): Charge
    {
        $subscription = Subscription::factory()->create();

        return Charge::create([
            'subscription_id' => $subscription->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_create_document_resolves_account_and_posts_correct_structure(): void
    {
        // Linet wraps responses in {status, body}. An existing account is found
        // via search/account and its id is carried into the document.
        Http::fake([
            '*/search/account' => Http::response(['status' => 200, 'body' => [['id' => 77]]]),
            '*/create/doc' => Http::response(['status' => 200, 'body' => ['id' => 4321, 'pdf' => 'https://app.linet.test/doc/4321.pdf']]),
        ]);

        $result = app(LinetClient::class)->issueDocument($this->charge(), VatCategory::Taxable, 'מנוי חודשי');

        $this->assertSame('4321', $result['document_id']);
        $this->assertSame('https://app.linet.test/doc/4321.pdf', $result['pdf_url']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/create/doc')) {
                return false;
            }

            $body = $request->data();

            return
                // Auth is carried in the request BODY, not headers.
                $body['login_id'] === 'lid'
                && $body['login_hash'] === 'lhash'
                && $body['login_company'] === '1'
                && $body['account_id'] === 77                     // resolved account
                && $body['doctype'] === '9'
                && $body['sendmail'] === 1
                && $body['docDet'][0]['item_id'] === '1'          // general item
                && $body['docDet'][0]['vat_cat_id'] === 1         // taxable category
                && $body['docDet'][0]['iItem'] === 118.0          // total incl VAT
                && $body['docDet'][0]['iItemWithVat'] === 1
                && $body['docCheq'][0]['sum'] === 118.0
                && $body['docCheq'][0]['type'] === 3;
        });
    }

    public function test_missing_account_is_created_before_the_document(): void
    {
        Http::fake([
            // Real "not found" shape: HTTP 200 with errorCode 1000 and a string body.
            '*/search/account' => Http::response(['status' => 200, 'errorCode' => 1000, 'body' => 'No items where found for model']),
            '*/create/account' => Http::response(['status' => 200, 'body' => ['id' => 909]]),
            '*/create/doc' => Http::response(['status' => 200, 'body' => ['id' => 5]]),
        ]);

        app(LinetClient::class)->issueDocument($this->charge(), VatCategory::Taxable, 'x');

        // The account model rejects a `company` parameter — it must never be sent.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create/account')
            && ! array_key_exists('company', $request->data()));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create/doc')
            && $request->data()['account_id'] === 909);
    }

    public function test_exempt_customer_uses_the_exempt_vat_category(): void
    {
        Http::fake([
            '*/search/account' => Http::response(['status' => 200, 'body' => [['id' => 1]]]),
            '*/create/doc' => Http::response(['status' => 200, 'body' => ['id' => 1]]),
        ]);

        app(LinetClient::class)->issueDocument($this->charge(), VatCategory::Exempt, 'x');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create/doc')
            && $request->data()['docDet'][0]['vat_cat_id'] === 2);
    }

    public function test_a_non_200_envelope_status_is_surfaced_as_an_error(): void
    {
        Http::fake([
            '*/search/account' => Http::response(['status' => 200, 'body' => [['id' => 1]]]),
            '*/create/doc' => Http::response(['status' => 401, 'message' => 'invalid login']),
        ]);

        $this->expectException(\RuntimeException::class);

        app(LinetClient::class)->issueDocument($this->charge(), VatCategory::Taxable, 'x');
    }

    public function test_a_200_with_a_nonzero_errorcode_is_treated_as_a_failure(): void
    {
        // Linet returns HTTP 200 + errorCode 1001 with field errors when it
        // REJECTS a document. This must surface as an error, never a false
        // "document created", and the field message must reach the caller.
        Http::fake([
            '*/search/account' => Http::response(['status' => 200, 'body' => [['id' => 1]]]),
            '*/create/doc' => Http::response([
                'status' => 200,
                'errorCode' => 1001,
                'body' => ['account_id' => ['מזהה חשבון לא יכול להיות ריק.']],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('account_id');

        app(LinetClient::class)->issueDocument($this->charge(), VatCategory::Taxable, 'x');
    }

    public function test_connection_check_reports_ok_on_a_clean_search_response(): void
    {
        Http::fake(['*/search/account' => Http::response(['status' => 200, 'body' => []])]);

        $this->assertTrue(app(LinetClient::class)->testConnection()->ok);
    }

    public function test_connection_check_warns_when_document_codes_are_missing(): void
    {
        config(['billing.linet.doctype' => null, 'billing.linet.vat_cat_exempt' => null]);
        Http::fake(['*/search/account' => Http::response(['status' => 200, 'body' => []])]);

        $result = app(LinetClient::class)->testConnection();

        // Credentials work, but the operator must learn about missing codes here
        // — not from a cryptic failed invoice later. The exempt VAT code is
        // included so exempt-customer setups are caught up front (Codex P2).
        $this->assertTrue($result->ok);
        $this->assertStringContainsString('קוד סוג מסמך', $result->message);
        $this->assertStringContainsString('קוד מע״מ — פטור', $result->message);
    }

    public function test_connection_check_reports_failure_when_linet_rejects_the_login(): void
    {
        Http::fake(['*/search/account' => Http::response(['status' => 401, 'message' => 'invalid login'])]);

        $result = app(LinetClient::class)->testConnection();
        $this->assertFalse($result->ok);
        $this->assertTrue($result->configured);
    }
}
