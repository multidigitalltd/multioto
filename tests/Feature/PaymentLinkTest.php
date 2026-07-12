<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\SendPaymentLinkJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Billing\ManualChargeService;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.cardcom.base_url' => 'https://secure.cardcom.test/api/v11/',
            'billing.cardcom.terminal_number' => '1000',
            'billing.cardcom.api_name' => 'apiname',
            'billing.cardcom.webhook_secret' => 'cc-secret',
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
            'billing.vat_rate' => 0.18,
            'mail.from.name' => 'מולטי דיגיטל',
        ]);
    }

    public function test_it_creates_a_hosted_page_and_sends_the_link_by_whatsapp(): void
    {
        Http::fake([
            '*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/ABC', 'LowProfileId' => 'LP1']),
            '*/api/sendText' => Http::response(['id' => 'wa-1']),
        ]);

        $customer = Customer::factory()->create(['phone' => '0501234567', 'name' => 'עסק בדיקה']);

        (new SendPaymentLinkJob($customer->id, 11800, 'שירות חד-פעמי', 'whatsapp'))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        // A pending one-off charge was created for the full amount.
        $charge = Charge::sole();
        $this->assertSame(11800, $charge->total_agorot);
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertSame('LP1', $charge->cardcom_low_profile_id);

        // The link reached the customer on WhatsApp.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], 'secure.cardcom.test/lp/ABC')
            && str_contains($request->data()['text'], '₪118.00'));
    }

    public function test_it_can_send_the_link_by_email(): void
    {
        Mail::fake();
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/XYZ', 'LowProfileId' => 'LP2'])]);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        (new SendPaymentLinkJob($customer->id, 5000, 'ייעוץ', 'email'))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, 'secure.cardcom.test/lp/XYZ'));
    }
}
