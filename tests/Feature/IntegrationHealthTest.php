<?php

namespace Tests\Feature;

use App\Services\Health\IntegrationHealth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHealthTest extends TestCase
{
    private function health(): IntegrationHealth
    {
        return app(IntegrationHealth::class);
    }

    public function test_unconfigured_integrations_report_not_configured(): void
    {
        config([
            'billing.cardcom.terminal_number' => null,
            'billing.cardcom.api_name' => null,
            'billing.linet.login_id' => null,
            'billing.waha.base_url' => null,
            'services.postmark.token' => null,
        ]);

        foreach (['cardcom', 'linet', 'waha', 'email'] as $key) {
            $result = $this->health()->check($key);
            $this->assertFalse($result->configured, "$key should be unconfigured");
            $this->assertSame('unconfigured', $result->state());
        }
    }

    public function test_cardcom_reports_ok_on_zero_response_code(): void
    {
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test', 'billing.cardcom.api_password' => 'secret']);
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x'])]);

        $result = $this->health()->check('cardcom');

        $this->assertTrue($result->ok);

        // A token-only request must NOT carry ApiPassword (only refunds/doc
        // creation use it). It DOES carry a Document — our terminal mandates one
        // (5046), and the default 'Auto' type lets Cardcom pick a supported one.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['Operation'] ?? null) === 'CreateTokenOnly'
                && ! array_key_exists('ApiPassword', $body)
                && array_key_exists('Amount', $body)
                && ($body['ISOCoinId'] ?? null) === 1
                && $body['TerminalNumber'] === 1000
                && ($body['Document']['DocumentTypeToCreate'] ?? null) === 'Auto';
        });
    }

    public function test_cardcom_document_type_is_configurable(): void
    {
        // The type is overridable per terminal (e.g. a non-fiscal 'Order').
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test', 'billing.cardcom.document_type' => 'Order']);
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x'])]);

        $this->health()->check('cardcom');

        Http::assertSent(fn ($request) => ($request->data()['Document']['DocumentTypeToCreate'] ?? null) === 'Order');
    }

    public function test_cardcom_can_disable_the_document(): void
    {
        // A terminal that doesn't require a document can opt out entirely.
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test', 'billing.cardcom.document_type' => '']);
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x'])]);

        $this->health()->check('cardcom');

        Http::assertSent(fn ($request) => ! array_key_exists('Document', $request->data()));
    }

    public function test_cardcom_reports_failure_on_nonzero_response_code(): void
    {
        config(['billing.cardcom.terminal_number' => '1000', 'billing.cardcom.api_name' => 'test']);
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 57, 'Description' => 'Invalid terminal'])]);

        $result = $this->health()->check('cardcom');

        $this->assertFalse($result->ok);
        $this->assertTrue($result->configured);
    }

    public function test_waha_reports_ok_when_session_is_working(): void
    {
        config(['billing.waha.base_url' => 'http://waha:3000', 'billing.waha.session' => 'default']);
        Http::fake(['*/api/sessions/default' => Http::response(['status' => 'WORKING'])]);

        $result = $this->health()->check('waha');

        $this->assertTrue($result->ok);
    }

    public function test_waha_reports_failure_when_qr_scan_needed(): void
    {
        config(['billing.waha.base_url' => 'http://waha:3000', 'billing.waha.session' => 'default']);
        Http::fake(['*/api/sessions/default' => Http::response(['status' => 'SCAN_QR_CODE'])]);

        $result = $this->health()->check('waha');

        $this->assertFalse($result->ok);
    }

    public function test_email_reports_ok_with_valid_postmark_token(): void
    {
        config(['services.postmark.token' => 'abc']);
        Http::fake(['api.postmarkapp.com/*' => Http::response(['Name' => 'My Server'])]);

        $result = $this->health()->check('email');

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('My Server', $result->message);
    }

    public function test_email_reports_failure_on_rejected_token(): void
    {
        config(['services.postmark.token' => 'bad']);
        Http::fake(['api.postmarkapp.com/*' => Http::response(['ErrorCode' => 10], 401)]);

        $result = $this->health()->check('email');

        $this->assertFalse($result->ok);
        $this->assertTrue($result->configured);
    }
}
