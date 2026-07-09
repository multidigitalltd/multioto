<?php

namespace Tests\Feature;

use App\Models\PaymentToken;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CardcomClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.cardcom.base_url' => 'https://secure.cardcom.test/api/v11',
            'billing.cardcom.terminal_number' => '1000',
            'billing.cardcom.api_name' => 'test',
        ]);
    }

    public function test_token_charge_sends_token_at_top_level_not_under_advanced(): void
    {
        Http::fake(['*/Transactions/Transaction' => Http::response(['ResponseCode' => 0, 'TranzactionId' => 555])]);

        $token = PaymentToken::factory()->create([
            'cardcom_token' => 'tok-abc',
            'expiry_month' => 12,
            'expiry_year' => 2030,
        ]);

        $result = app(CardcomClient::class)->chargeToken($token, 11800, 'מנוי חודשי', 'sub-1-20260101-a1');

        $this->assertTrue($result->success);
        $this->assertSame('555', $result->transactionId);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['Token'] === 'tok-abc'                  // top-level Token
                && ! array_key_exists('Advanced', $body)         // not wrapped
                && $body['Amount'] === 118.0                      // agorot → shekels
                && $body['ISOCoinId'] === 1
                && $body['ExternalUniqTranId'] === 'sub-1-20260101-a1'
                && $body['CardExpirationMMYY'] === '1230'        // MMYY from stored expiry
                && $body['TerminalNumber'] === 1000;             // integer
        });
    }

    public function test_a_nonzero_response_code_is_a_failed_charge(): void
    {
        Http::fake(['*/Transactions/Transaction' => Http::response(['ResponseCode' => 57, 'Description' => 'declined'])]);

        $token = PaymentToken::factory()->create(['cardcom_token' => 'tok-x']);

        $result = app(CardcomClient::class)->chargeToken($token, 5000, 'x', 'ext-1');

        $this->assertFalse($result->success);
        $this->assertSame('57', $result->responseCode);
    }
}
