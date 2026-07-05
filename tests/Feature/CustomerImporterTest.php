<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Import\CustomerImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerImporterTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $name = 'אחזקה עסקית', int $price = 19900): Plan
    {
        return Plan::factory()->create(['name' => $name, 'price_agorot' => $price, 'active' => true]);
    }

    public function test_it_imports_customers_with_site_and_trialing_subscription(): void
    {
        $plan = $this->plan();

        $rows = [
            ['שם' => 'ישראל ישראלי', 'אימייל' => 'israel@example.co.il', 'טלפון' => '0501234567', 'דומיין' => 'example.co.il', 'תוכנית' => 'אחזקה עסקית', 'פטור ממע"מ' => 'לא'],
            ['שם' => 'עוסק פטור', 'אימייל' => 'patur@example.co.il', 'סוג עסק' => 'עוסק פטור', 'פטור ממע"מ' => 'כן', 'תוכנית' => 'אחזקה עסקית', 'מחיר' => '250'],
        ];

        $result = (new CustomerImporter)->import($rows);

        $this->assertSame(2, $result->importedCount());
        $this->assertSame(0, $result->skippedCount());

        $israel = Customer::where('email', 'israel@example.co.il')->first();
        $this->assertNotNull($israel);
        $this->assertSame('example.co.il', $israel->sites()->value('domain'));

        $sub = Subscription::where('customer_id', $israel->id)->first();
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertNull($sub->token_id);

        $patur = Customer::where('email', 'patur@example.co.il')->first();
        $this->assertTrue($patur->vat_exempt);
        $this->assertSame(BusinessType::ExemptDealer, $patur->business_type);
        // Price override 250 ₪ → 25000 agorot.
        $this->assertSame(25000, Subscription::where('customer_id', $patur->id)->value('price_agorot_override'));
    }

    public function test_it_skips_rows_without_a_name_and_reports_them(): void
    {
        $this->plan();

        $result = (new CustomerImporter)->import([
            ['שם' => '', 'אימייל' => 'noname@example.co.il'],
            ['שם' => 'תקין', 'אימייל' => 'ok@example.co.il'],
        ]);

        $this->assertSame(1, $result->importedCount());
        $this->assertSame(1, $result->skippedCount());
        $this->assertStringContainsString('חסר שם', $result->skipped[0]['reason']);
    }

    public function test_it_skips_duplicates_by_email_or_phone(): void
    {
        $this->plan();
        Customer::factory()->create(['email' => 'exists@example.co.il']);

        $result = (new CustomerImporter)->import([
            ['שם' => 'כפול', 'אימייל' => 'exists@example.co.il'],
        ], skipDuplicates: true);

        $this->assertSame(0, $result->importedCount());
        $this->assertSame(1, $result->skippedCount());
        $this->assertStringContainsString('קיים', $result->skipped[0]['reason']);
    }

    public function test_it_skips_rows_when_named_plan_is_not_found(): void
    {
        $this->plan('אחזקה בסיסית');

        $result = (new CustomerImporter)->import([
            ['שם' => 'לקוח', 'תוכנית' => 'מסלול שלא קיים'],
        ]);

        $this->assertSame(0, $result->importedCount());
        $this->assertStringContainsString('תוכנית', $result->skipped[0]['reason']);
    }

    public function test_english_headers_also_work(): void
    {
        $this->plan();

        $result = (new CustomerImporter)->import([
            ['name' => 'John', 'email' => 'john@example.com', 'domain' => 'john.com', 'plan' => 'אחזקה עסקית'],
        ]);

        $this->assertSame(1, $result->importedCount());
        $this->assertDatabaseHas('customers', ['name' => 'John', 'email' => 'john@example.com']);
    }
}
