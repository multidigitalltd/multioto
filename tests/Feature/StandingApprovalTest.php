<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\StandingApproval;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\HostingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Standing ("always approve") grants: the owner approves a kind of action
 * once with "אשר תמיד", and future proposals of that kind execute immediately
 * with an after-the-fact report — never for customer replies, destructive
 * tools or money operations.
 */
class StandingApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
            'billing.waha.owner_number' => '0501112222', // → 972501112222@c.us
            'billing.waha.default_country_code' => '972',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w'])]);
    }

    private function gate(): ApprovalGate
    {
        return app(ApprovalGate::class);
    }

    private function expectCacheClears(int $times): Site
    {
        $site = Site::factory()->create();
        $hosting = Mockery::mock(HostingClient::class);
        $hosting->shouldReceive('clearCache')->times($times);
        $this->app->instance(HostingClient::class, $hosting);

        return $site;
    }

    public function test_approve_always_executes_and_creates_a_standing_grant(): void
    {
        $site = $this->expectCacheClears(1);
        $action = $this->gate()->propose('site_fix', 'ניקוי מטמון', ['site_id' => $site->id, 'fix' => 'clear_cache']);
        $this->assertSame(ActionStatus::Pending, $action->status);

        $reply = $this->gate()->handleOwnerMessage('972501112222@c.us', "אשר תמיד {$action->id}");

        $this->assertStringContainsString('אושרה ובוצעה', (string) $reply);
        $this->assertStringContainsString('אישור קבוע', (string) $reply);
        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertTrue(StandingApproval::where('action_key', 'site_fix:clear_cache')->where('enabled', true)->exists());
    }

    public function test_a_matching_proposal_then_runs_automatically_with_a_report(): void
    {
        $site = $this->expectCacheClears(1);
        StandingApproval::create(['action_key' => 'site_fix:clear_cache', 'label' => 'ניקוי מטמון']);

        $action = $this->gate()->propose('site_fix', 'ניקוי מטמון אחרי תקלה', ['site_id' => $site->id, 'fix' => 'clear_cache']);

        $this->assertSame(ActionStatus::Executed, $action->status);
        $this->assertNotNull($action->standing_approval_id);
        $this->assertSame(1, StandingApproval::first()->uses_count);
        // The owner is told it RAN — not asked to approve.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && str_contains((string) $request->data()['text'], 'בוצע אוטומטית'));
    }

    public function test_a_disabled_grant_leaves_the_proposal_pending(): void
    {
        $site = $this->expectCacheClears(0);
        StandingApproval::create(['action_key' => 'site_fix:clear_cache', 'label' => 'ניקוי מטמון', 'enabled' => false]);

        $action = $this->gate()->propose('site_fix', 'ניקוי מטמון', ['site_id' => $site->id, 'fix' => 'clear_cache']);

        $this->assertSame(ActionStatus::Pending, $action->status);
    }

    public function test_customer_replies_are_never_eligible(): void
    {
        $this->assertNull(ApprovalGate::standingKeyFor('ticket_reply', ['reply' => 'שלום']));

        $action = PendingAction::create([
            'type' => 'ticket_reply', 'status' => ActionStatus::Pending,
            'summary' => 'תשובה', 'payload' => ['reply' => 'שלום'], 'proposed_by' => 'ai',
        ]);

        $reply = $this->gate()->handleOwnerMessage('972501112222@c.us', "אשר תמיד {$action->id}");

        $this->assertStringContainsString('אי אפשר לקבוע אישור קבוע', (string) $reply);
        $this->assertSame(ActionStatus::Pending, $action->fresh()->status);
        $this->assertSame(0, StandingApproval::count());
    }

    public function test_destructive_tools_and_money_operations_are_never_eligible(): void
    {
        $gate = $this->gate();

        // wp_file_write matches the tier-3 "file_write" risk rule.
        $this->assertNull(ApprovalGate::standingKeyFor('site_action', ['tool' => 'wp_file_write']));
        $this->assertSame('site_action:wp_cache_flush', ApprovalGate::standingKeyFor('site_action', ['tool' => 'wp_cache_flush']));

        $this->assertNull(ApprovalGate::standingKeyFor('system_action', ['operation' => 'send_payment_request']));
        $this->assertNull(ApprovalGate::standingKeyFor('system_action', ['operation' => 'mark_collected']));
        $this->assertSame('system_action:close_ticket', ApprovalGate::standingKeyFor('system_action', ['operation' => 'close_ticket']));
    }

    public function test_the_approval_message_offers_always_only_when_eligible(): void
    {
        $site = $this->expectCacheClears(0);

        $eligible = $this->gate()->propose('site_fix', 'ניקוי מטמון', ['site_id' => $site->id, 'fix' => 'clear_cache']);
        $this->assertSame(ActionStatus::Pending, $eligible->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && str_contains((string) $request->data()['text'], "אשר תמיד {$eligible->id}"));

        $this->gate()->propose('ticket_reply', 'תשובה', ['reply' => 'שלום']);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->data()['text'], 'תשובה')
            && str_contains((string) $request->data()['text'], 'אשר תמיד'));
    }
}
