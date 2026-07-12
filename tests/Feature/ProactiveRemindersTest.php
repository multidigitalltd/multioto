<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\SendProactiveRemindersJob;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProactiveRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_digest_reports_renewals_expiring_cards_and_open_debt(): void
    {
        config(['billing.reminders.renewal_days' => 3, 'billing.reminders.card_expiry_months' => 1]);

        // A renewal due tomorrow.
        Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->addDay(),
        ]);

        // A saved card expiring this month.
        PaymentToken::factory()->create([
            'status' => TokenStatus::Active,
            'expiry_month' => (int) now()->format('m'),
            'expiry_year' => (int) now()->format('Y'),
            'card_last4' => '4242',
        ]);

        // Money already owed.
        Subscription::factory()->create(['status' => SubscriptionStatus::PastDue]);

        $captured = null;
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->with('🔔 תזכורות יומיות', Mockery::capture($captured));
        $this->app->instance(TeamNotifier::class, $team);

        SendProactiveRemindersJob::dispatchSync();

        $this->assertStringContainsString('חידושים בקרוב', $captured);
        $this->assertStringContainsString('כרטיסים שעומדים לפוג', $captured);
        $this->assertStringContainsString('...4242', $captured);
        $this->assertStringContainsString('חוב פתוח', $captured);
    }

    public function test_a_card_expiring_far_in_the_future_is_not_flagged(): void
    {
        $this->expectNotToPerformAssertions(); // Mockery ->never() enforces the check.
        config(['billing.reminders.card_expiry_months' => 1]);

        PaymentToken::factory()->create([
            'status' => TokenStatus::Active,
            'expiry_month' => (int) now()->format('m'),
            'expiry_year' => (int) now()->addYears(2)->format('Y'),
        ]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->never();
        $this->app->instance(TeamNotifier::class, $team);

        SendProactiveRemindersJob::dispatchSync();
    }

    public function test_nothing_is_sent_when_there_is_nothing_to_report(): void
    {
        $this->expectNotToPerformAssertions(); // Mockery ->never() enforces the check.
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->never();
        $this->app->instance(TeamNotifier::class, $team);

        SendProactiveRemindersJob::dispatchSync();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
