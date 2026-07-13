<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\WebhookSource;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CardSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncing_reconciles_a_card_and_collects_the_debt(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);
        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0,
            'TokenInfo' => ['Token' => 'tok-sync', 'CardLast4Digits' => '4321'],
        ])]);

        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['pending_card_lp_id' => 'lp-xyz']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::PastDue,
            'token_id' => null,
        ]);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('syncCard');

        $fresh = $customer->fresh();
        $this->assertNotNull($fresh->default_token_id);          // token stored
        $this->assertNull($fresh->pending_card_lp_id);            // reconciled → cleared
        $this->assertSame('4321', $fresh->paymentTokens()->sole()->card_last4);
        Queue::assertPushed(ChargeSubscriptionJob::class, fn ($job) => $job->subscriptionId === $subscription->id);
    }

    public function test_the_webhook_clears_the_pending_marker_for_its_session(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);

        $customer = Customer::factory()->create(['pending_card_lp_id' => 'lp-42']);
        $event = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile', 'lp-42', [
            'LowProfileId' => 'lp-42',
            'ReturnValue' => (string) $customer->id,
            'ResponseCode' => 0,
            'TokenInfo' => ['Token' => 'tok-wh', 'CardLast4Digits' => '9999'],
        ])[0];

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        // Token saved AND the stale pending marker cleared, so a later manual
        // sync can't re-process the same session.
        $this->assertNotNull($customer->fresh()->default_token_id);
        $this->assertNull($customer->fresh()->pending_card_lp_id);
    }

    public function test_a_pasted_low_profile_id_reconciles_a_card_with_no_pending_request(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);
        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0,
            'TokenInfo' => ['Token' => 'tok-paste', 'CardLast4Digits' => '5555'],
        ])]);

        $this->actingAs(User::factory()->create());
        // No pending request — the team pastes the id from the Cardcom report.
        $customer = Customer::factory()->create(['pending_card_lp_id' => null]);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('syncCard', ['low_profile_id' => 'lp-from-cardcom']);

        $this->assertNotNull($customer->fresh()->default_token_id);
        $this->assertSame('5555', $customer->fresh()->paymentTokens()->sole()->card_last4);
    }

    public function test_syncing_with_no_pending_request_saves_no_token(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['pending_card_lp_id' => null]);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('syncCard');

        $this->assertNull($customer->fresh()->default_token_id);
        $this->assertSame(0, $customer->paymentTokens()->count());
    }

    public function test_syncing_when_the_customer_has_not_entered_a_card_yet(): void
    {
        Http::fake(['*/LowProfile/GetLpResult' => Http::response(['ResponseCode' => 0, 'TokenInfo' => []])]);
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['pending_card_lp_id' => 'lp-pending']);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('syncCard');

        // No token yet → nothing saved, and the pending id is kept for a later retry.
        $this->assertNull($customer->fresh()->default_token_id);
        $this->assertSame('lp-pending', $customer->fresh()->pending_card_lp_id);
    }
}
