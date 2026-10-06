<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SiteAgentUsageDashboard;
use App\Models\AiCustomerUsage;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\AiUsageAttribution;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Services\SiteAgent\SiteAgentUsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The site-agent usage screen: what each customer sent, paid, and cost in AI —
 * with the ones the product loses money on first.
 */
class SiteAgentUsageDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.ai.usd_ils_rate' => 4.0, 'billing.ai.pricing' => ['*' => [1.00, 5.00]]]);
    }

    public function test_each_customer_is_set_against_what_their_ai_cost(): void
    {
        $cheap = $this->customer('לקוח רווחי', paidAgorot: 17700);
        $costly = $this->customer('לקוח יקר', paidAgorot: 1000);

        // 1M input tokens at $1 = $1 = ₪4.00; 1M output at $5 = ₪20.00.
        AiCustomerUsage::record($costly['customer']->id, 'anthropic', 'm', 1_000_000, 1_000_000);
        app(SiteAgentUsageMeter::class)->record($costly['number'], 'wamid.1');
        app(SiteAgentUsageMeter::class)->record($costly['number'], 'wamid.2');

        $rows = app(SiteAgentUsageReport::class)->rows(30);

        // The one losing money first.
        $this->assertSame('לקוח יקר', $rows[0]['name']);
        $this->assertSame(2400, $rows[0]['ai_cost_agorot']);
        $this->assertSame(1000 - 2400, $rows[0]['margin_agorot']);
        $this->assertSame(2, $rows[0]['messages']);
        $this->assertSame(17700, $rows[1]['revenue_agorot']);
        $this->assertSame($cheap['customer']->id, $rows[1]['customer_id']);
    }

    public function test_ai_calls_are_booked_to_the_customer_they_were_made_for(): void
    {
        $attribution = app(AiUsageAttribution::class);
        $customer = Customer::factory()->create();

        $this->assertNull($attribution->current());

        $inside = $attribution->for($customer->id, fn (): ?int => $attribution->current());

        $this->assertSame($customer->id, $inside);
        // And nothing leaks to the next piece of work.
        $this->assertNull($attribution->current());
    }

    public function test_the_ceiling_is_shown_with_how_much_of_it_is_used(): void
    {
        $one = $this->customer('לקוח עם תקרה', paidAgorot: 0);
        $one['subscription']->update(['site_agent_message_cap' => 10]);

        foreach (range(1, 8) as $i) {
            app(SiteAgentUsageMeter::class)->record($one['number'], "wamid.{$i}");
        }

        $row = app(SiteAgentUsageReport::class)->rows(30)->first();

        $this->assertSame(10, $row['cap']);
        $this->assertSame(8, $row['cap_used']);
    }

    public function test_the_page_is_for_admins_and_renders(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(SiteAgentUsageDashboard::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->customer('חברת בדיקה', paidAgorot: 17700);

        Livewire::test(SiteAgentUsageDashboard::class)
            ->assertSeeText('חברת בדיקה')
            ->assertSeeText('₪177.00')
            ->set('windowDays', 9999)
            ->assertSet('windowDays', 30);
    }

    /** @return array{customer: Customer, subscription: Subscription, number: SiteAgentSubscriber} */
    private function customer(string $name, int $paidAgorot): array
    {
        $customer = Customer::factory()->create(['name' => $name]);
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $plan = Plan::create([
            'name' => 'בוט', 'price_agorot' => 14900, 'message_price_agorot' => 15, 'vat_applies' => true,
            'billing_interval' => 'monthly', 'active' => true, 'includes_site_agent' => true,
        ]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id, 'site_id' => $site->id, 'status' => SubscriptionStatus::Active,
        ]);

        if ($paidAgorot > 0) {
            $subscription->charges()->create([
                'customer_id' => $customer->id, 'amount_agorot' => $paidAgorot, 'vat_agorot' => 0, 'total_agorot' => $paidAgorot,
                'currency' => 'ILS', 'status' => ChargeStatus::Succeeded, 'attempt_number' => 1,
                'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'charged_at' => now()->subDay(),
            ]);
        }

        $number = SiteAgentSubscriber::create([
            'phone' => '9725'.random_int(10000000, 99999999), 'customer_id' => $customer->id, 'site_id' => $site->id, 'verified_at' => now(),
        ]);

        $this->assertSame(0, SiteAgentUsage::where('customer_id', $customer->id)->count());

        return ['customer' => $customer, 'subscription' => $subscription, 'number' => $number];
    }
}
