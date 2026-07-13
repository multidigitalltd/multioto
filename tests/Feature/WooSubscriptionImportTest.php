<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Import\WooSubscriptionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WooSubscriptionImportTest extends TestCase
{
    use RefreshDatabase;

    private function wxr(): string
    {
        $item = fn (array $meta, string $status, int $id) => '<item><title>sub</title>'
            .'<wp:post_id>'.$id.'</wp:post_id>'
            .'<wp:post_type>shop_subscription</wp:post_type>'
            .'<wp:status>'.$status.'</wp:status>'
            .implode('', array_map(
                fn ($k, $v) => "<wp:postmeta><wp:meta_key>{$k}</wp:meta_key><wp:meta_value>{$v}</wp:meta_value></wp:postmeta>",
                array_keys($meta),
                $meta,
            )).'</item>';

        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>';
        $xml .= $item([
            '_billing_email' => 'dana@example.com', '_billing_first_name' => 'דנה', '_billing_last_name' => 'לוי',
            '_billing_company' => 'חברת דנה', '_billing_phone' => '0501234567', 'is_vat_exempt' => 'no',
            '_order_total' => '590.00', '_schedule_next_payment' => '2026-08-07 00:00:00',
        ], 'wc-active', 101);
        $xml .= $item([
            '_billing_email' => 'gone@example.com', '_order_total' => '100.00',
        ], 'wc-cancelled', 102);
        $xml .= $item([
            '_billing_email' => 'exempt@example.com', '_billing_first_name' => 'עמותה', 'is_vat_exempt' => 'yes',
            '_order_total' => '300.00', '_schedule_next_payment' => '2026-01-01 00:00:00',
        ], 'wc-on-hold', 103);
        $xml .= '</channel></rss>';

        $path = tempnam(sys_get_temp_dir(), 'wxr').'.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    public function test_it_imports_active_and_onhold_skips_cancelled_and_prices_correctly(): void
    {
        Mail::fake();
        $path = $this->wxr();

        $result = (new WooSubscriptionImporter)->import($path);
        @unlink($path);

        // Cancelled dropped; active + on-hold imported.
        $this->assertSame(2, $result->created);
        $this->assertSame(2, $result->customersCreated);
        $this->assertNull(Customer::where('email', 'gone@example.com')->first());

        // Non-exempt 590 total → pre-VAT base 500.00 (÷1.18); renewal date preserved.
        $active = Subscription::whereHas('customer', fn ($q) => $q->where('email', 'dana@example.com'))->sole();
        $this->assertSame(50000, $active->price_agorot_override);
        $this->assertSame(SubscriptionStatus::Trialing, $active->status);
        $this->assertSame('2026-08-07', $active->next_charge_at->toDateString());
        $this->assertSame('חברת דנה', $active->customer->name);

        // Exempt customer → base kept as the full total (no VAT split); flagged as debtor.
        $exempt = Subscription::whereHas('customer', fn ($q) => $q->where('email', 'exempt@example.com'))->sole();
        $this->assertSame(30000, $exempt->price_agorot_override);
        $this->assertStringContainsString('חוב', $exempt->name);
        $this->assertCount(1, $result->debtors);

        // A migration must never email a customer.
        Mail::assertNothingSent();
    }

    public function test_it_skips_customers_that_already_have_a_subscription(): void
    {
        $path = $this->wxr();
        (new WooSubscriptionImporter)->import($path);
        $result = (new WooSubscriptionImporter)->import($path);
        @unlink($path);

        $this->assertSame(0, $result->created);
        $this->assertSame(2, Subscription::count());
    }
}
