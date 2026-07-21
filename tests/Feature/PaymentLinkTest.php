<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\IssueProformaJob;
use App\Jobs\SendPaymentLinkJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Billing\ManualChargeService;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        Bus::fake([IssueProformaJob::class]);
    }

    public function test_it_creates_a_hosted_page_and_sends_a_cancelable_link_by_whatsapp(): void
    {
        Http::fake([
            '*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/ABC', 'LowProfileId' => 'LP1']),
            '*/api/sendText' => Http::response(['id' => 'wa-1']),
        ]);

        $customer = Customer::factory()->create(['phone' => '0501234567', 'name' => 'עסק בדיקה']);

        (new SendPaymentLinkJob($customer->id, 11800, 'שירות חד-פעמי', 'whatsapp'))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        // A pending one-off charge holds the raw Cardcom page for our gateway.
        $charge = Charge::sole();
        $this->assertSame(11800, $charge->total_agorot);
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertSame('LP1', $charge->cardcom_low_profile_id);
        $this->assertSame('https://secure.cardcom.test/lp/ABC', $charge->cardcom_pay_url);

        // The customer gets OUR cancelable gateway link, not the raw Cardcom URL.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], '/billing/pay/'.$charge->id)
            && ! str_contains($request->data()['text'], 'secure.cardcom.test')
            && str_contains($request->data()['text'], '₪118.00'));

        // The proforma issuance is queued for the demand.
        Bus::assertDispatched(IssueProformaJob::class, fn ($job) => $job->chargeId === $charge->id);
    }

    public function test_it_can_send_the_link_by_email(): void
    {
        Mail::fake();
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/XYZ', 'LowProfileId' => 'LP2'])]);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        (new SendPaymentLinkJob($customer->id, 5000, 'ייעוץ', 'email'))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, '/billing/pay/'));
    }

    public function test_a_single_line_request_still_details_the_product_and_amount(): void
    {
        Mail::fake();
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/ONE', 'LowProfileId' => 'LP3'])]);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        (new SendPaymentLinkJob($customer->id, 5000, 'ייעוץ SEO', 'email'))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, '• ייעוץ SEO — ₪50.00')
            && str_contains($mail->bodyText, 'סה״כ לתשלום: ₪50.00'));
    }

    public function test_line_items_are_itemised_in_the_request_and_ride_to_the_charge(): void
    {
        Mail::fake();
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/MANY', 'LowProfileId' => 'LP4'])]);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);
        $lines = [
            ['name' => 'אחסון שנתי', 'qty' => 2, 'unit_price_agorot' => 10000],
            ['name' => 'תוסף SEO', 'qty' => 1, 'unit_price_agorot' => 8000],
        ];

        (new SendPaymentLinkJob($customer->id, 28000, 'חבילה', 'email', $lines))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        Mail::assertSent(NotificationMail::class, function ($mail): bool {
            return str_contains($mail->bodyText, '• אחסון שנתי — 2 × ₪100.00 = ₪200.00')
                && str_contains($mail->bodyText, '• תוסף SEO — ₪80.00')
                && str_contains($mail->bodyText, 'סה״כ לתשלום: ₪280.00');
        });

        $charge = Charge::sole();
        $this->assertSame(28000, $charge->total_agorot);
        $this->assertSame($lines, $charge->lines);
    }

    public function test_a_transfer_only_demand_creates_no_cardcom_page_and_shows_bank_details(): void
    {
        Mail::fake();
        Http::fake(); // nothing should hit Cardcom
        config(['billing.signup.instructions.bank_transfer' => 'בנק לאומי · סניף 800 · חשבון 12345 · ע"ש מולטי דיגיטל']);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        (new SendPaymentLinkJob($customer->id, 9000, 'ייעוץ', 'email', [], ['transfer']))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        // A demand still exists (to track + hang a proforma off), but with no page.
        $charge = Charge::sole();
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertNull($charge->cardcom_pay_url);
        Http::assertNothingSent();

        // The email carries the bank details and no card link.
        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, 'בנק לאומי')
            && str_contains($mail->bodyText, 'העברה בנקאית')
            && ! str_contains($mail->bodyText, '/billing/pay/'));
    }

    public function test_a_demand_can_offer_both_a_card_link_and_a_bank_transfer(): void
    {
        Mail::fake();
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.test/lp/BOTH', 'LowProfileId' => 'LP5'])]);
        config(['billing.signup.instructions.bank_transfer' => 'IBAN IL12 3456']);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        (new SendPaymentLinkJob($customer->id, 12000, 'שירות', 'email', [], ['link', 'transfer']))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, '/billing/pay/')
            && str_contains($mail->bodyText, 'IBAN IL12 3456'));
    }

    public function test_a_demand_captures_and_offers_a_bit_link_when_cardcom_returns_one(): void
    {
        Mail::fake();
        Http::fake(['*/LowProfile/Create' => Http::response([
            'ResponseCode' => 0,
            'Url' => 'https://secure.cardcom.test/lp/BIT',
            'UrlToBit' => 'https://secure.cardcom.test/bit/XYZ',
            'LowProfileId' => 'LP6',
        ])]);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        (new SendPaymentLinkJob($customer->id, 12000, 'שירות', 'email', [], ['link']))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));

        // The Bit URL is stored on the charge…
        $this->assertSame('https://secure.cardcom.test/bit/XYZ', Charge::sole()->cardcom_bit_url);
        // …and the email offers a Bit link (our own signed /bit gateway) + "Bit".
        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, '/bit')
            && str_contains($mail->bodyText, 'Bit'));
    }
}
