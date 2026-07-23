<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Customers\OnboardingChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function itemsByKey(Customer $customer): array
    {
        return collect(app(OnboardingChecklist::class)->items($customer))->keyBy('key')->all();
    }

    public function test_a_brand_new_customer_starts_with_everything_open(): void
    {
        $customer = Customer::factory()->create(['terms_accepted_at' => null, 'default_token_id' => null]);

        $items = $this->itemsByKey($customer);

        foreach ($items as $item) {
            $this->assertFalse($item['done'], "פריט {$item['key']} אמור להיות פתוח");
        }

        $this->assertSame(['done' => 0, 'total' => count(OnboardingChecklist::ITEMS)],
            app(OnboardingChecklist::class)->progress($customer));
    }

    public function test_completed_steps_are_detected_automatically(): void
    {
        $customer = Customer::factory()->create(['terms_accepted_at' => now()]);
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $token->id]);

        Site::factory()->create([
            'customer_id' => $customer->id,
            'monitor_enabled' => true,
            'mcp_last_seen_at' => now(),
        ]);
        Subscription::factory()->create(['customer_id' => $customer->id, 'status' => SubscriptionStatus::Active]);
        NotificationLog::record('email', NotificationType::Welcome, 'c@x.co', 'ברוכים הבאים', 'תוכן', $customer->id);

        $items = $this->itemsByKey($customer->refresh());

        foreach (['terms_signed', 'site_linked', 'subscription_active', 'card_captured', 'plugin_connected', 'monitoring_on', 'welcome_sent'] as $key) {
            $this->assertTrue($items[$key]['done'], "פריט {$key} אמור להיות מסומן");
            $this->assertTrue($items[$key]['auto'], "פריט {$key} אמור להיות אוטומטי");
        }
    }

    public function test_a_manual_tick_is_stored_and_reversible(): void
    {
        $customer = Customer::factory()->create(['default_token_id' => null]);
        $service = app(OnboardingChecklist::class);

        // A bank-transfer customer has no stored card — the operator ticks the
        // payment-method step by hand.
        $service->toggle($customer, 'card_captured');
        $items = $this->itemsByKey($customer->refresh());
        $this->assertTrue($items['card_captured']['done']);
        $this->assertTrue($items['card_captured']['manual']);
        $this->assertFalse($items['card_captured']['auto']);

        // And can untick it.
        $service->toggle($customer, 'card_captured');
        $this->assertFalse($this->itemsByKey($customer->refresh())['card_captured']['done']);
    }

    public function test_an_unknown_key_is_ignored(): void
    {
        $customer = Customer::factory()->create();

        app(OnboardingChecklist::class)->toggle($customer, 'not_a_real_step');

        $this->assertNull($customer->refresh()->onboarding_checklist);
    }

    public function test_the_customer_page_toggles_a_step_from_the_ui(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['default_token_id' => null]);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertSeeText('צ׳ק-ליסט קליטה')
            ->call('toggleOnboarding', 'welcome_sent');

        $this->assertTrue((bool) data_get($customer->refresh()->onboarding_checklist, 'welcome_sent.done'));
    }
}
