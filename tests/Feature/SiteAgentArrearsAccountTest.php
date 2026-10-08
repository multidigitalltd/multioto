<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\SiteAgent\SiteAgentAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class SiteAgentArrearsAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bot_quotes_the_personal_month_with_the_custom_price_and_extra_seat(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0));
        $customer = Customer::factory()->create(['vat_exempt' => false]);
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $plan = Plan::factory()->create([
            'includes_site_agent' => true, 'billing_interval' => 'yearly', 'vat_applies' => false,
            'price_agorot' => 14901, 'extra_number_price_agorot' => 4907,
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id, 'site_id' => $site->id, 'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active, 'price_agorot_override' => 12001, 'agent_extra_numbers' => 1,
            ...SiteAgentArrearsBilling::initializeDates(now()),
        ]);
        $subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id, 'phone' => '972501234567', 'verified_at' => now(),
        ]);

        $account = json_decode((new ReflectionMethod(SiteAgentAssistant::class, 'account'))
            ->invoke(app(SiteAgentAssistant::class), $subscriber, $site), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('₪10.00 לחודש אישי', $account['plan_price']);
        $this->assertSame('₪4.08 לחודש אישי', $account['extra_number_price']);
        $this->assertSame('₪14.08', $account['estimated_current_cycle_total']);
        $this->assertSame('08/11/2026', $account['next_charge']);
        $this->assertStringContainsString('חריגת הודעות יוצאות ותוספות כתיבה יחד', $account['billing_timing']);
        $this->assertFalse($account['prices_include_vat']);
    }
}
