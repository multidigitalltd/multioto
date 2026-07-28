<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\PlanContentChangeJob;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Agent\ChangeRequestPlanner;
use App\Services\Agent\ContentChangeRunner;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use App\Services\Support\AgentReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * "בקשת שינוי בוואטסאפ" — a customer asks for a small content change, the owner
 * approves once, and it goes live. The guards matter more than the happy path:
 * nothing may reach a site without an explicit approval.
 */
class ContentChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function ticketWithSite(int $siteCount = 1): Ticket
    {
        $customer = Customer::factory()->create(['name' => 'ברסקי נכסים']);

        foreach (range(1, $siteCount) as $i) {
            Site::factory()->create([
                'customer_id' => $customer->id,
                'domain' => "site{$i}.co.il",
                'mcp_enabled' => true,
                'mcp_endpoint' => "https://site{$i}.co.il/wp-json/md-agent/v1/mcp",
            ]);
        }

        return Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'בקשת שינוי',
            'status' => TicketStatus::Open,
        ]);
    }

    /** A planner whose model answers with the given structured plan. */
    private function planner(?array $answer, array $pages = [['id' => 12, 'title' => 'דף הבית']]): ChangeRequestPlanner
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn($answer);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturn(['content' => []]);
        $mcp->shouldReceive('textContent')->andReturn((string) json_encode($pages));

        return new ChangeRequestPlanner($ai, $mcp);
    }

    public function test_a_clear_request_becomes_a_pending_proposal_naming_the_page_and_text(): void
    {
        $ticket = $this->ticketWithSite();

        $planner = $this->planner([
            'can_do' => true,
            'page_id' => 12,
            'addition' => 'אנחנו פתוחים גם בימי שישי בין 9:00 ל-13:00.',
            'summary' => 'הוספת שעות פתיחה',
        ]);

        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldReceive('propose')->once()->withArgs(function (string $type, string $summary, array $payload) {
            return $type === 'content_change'
                && str_contains($summary, 'דף הבית')
                && str_contains($summary, 'פתוחים גם בימי שישי')
                && $payload['page_id'] === 12;
        })->andReturn(new PendingAction(['id' => 1]));

        (new PlanContentChangeJob($ticket->id, 'תוסיפו לדף הבית שאנחנו פתוחים גם בשישי 9-13'))
            ->handle($planner, $gate);
    }

    public function test_an_ambiguous_request_is_left_to_a_human(): void
    {
        $ticket = $this->ticketWithSite();

        // The model declines — "make the site look better" is not a plan.
        $planner = $this->planner(['can_do' => false]);

        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldNotReceive('propose');

        (new PlanContentChangeJob($ticket->id, 'תעשו שהאתר ייראה יותר טוב'))->handle($planner, $gate);
    }

    public function test_a_page_outside_the_offered_list_is_refused(): void
    {
        $ticket = $this->ticketWithSite();

        // The model returns a page id we never offered — a hallucination that
        // must never become an edit.
        $planner = $this->planner(['can_do' => true, 'page_id' => 999, 'addition' => 'טקסט', 'summary' => 'x']);

        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldNotReceive('propose');

        (new PlanContentChangeJob($ticket->id, 'תוסיפו משהו'))->handle($planner, $gate);
    }

    public function test_a_customer_with_two_connected_sites_is_never_guessed_at(): void
    {
        $ticket = $this->ticketWithSite(siteCount: 2);

        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldNotReceive('propose');

        (new PlanContentChangeJob($ticket->id, 'תוסיפו לדף הבית שעות פתיחה'))
            ->handle($this->planner(['can_do' => true, 'page_id' => 12, 'addition' => 'x', 'summary' => 'y']), $gate);
    }

    public function test_an_unidentified_sender_never_drives_a_site_change(): void
    {
        $ticket = Ticket::create([
            'customer_id' => null,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'בקשה',
            'status' => TicketStatus::Open,
        ]);

        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldNotReceive('propose');

        (new PlanContentChangeJob($ticket->id, 'תוסיפו לדף הבית שעות פתיחה'))
            ->handle($this->planner(['can_do' => true, 'page_id' => 12, 'addition' => 'x', 'summary' => 'y']), $gate);
    }

    public function test_execution_appends_to_the_live_page_not_to_a_stale_snapshot(): void
    {
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site1.co.il/wp-json/md-agent/v1/mcp',
        ]);

        $action = PendingAction::create([
            'type' => 'content_change',
            'status' => ActionStatus::Approved,
            'summary' => 'הוספת שעות פתיחה',
            'payload' => ['site_id' => $site->id, 'page_id' => 12, 'page_title' => 'דף הבית', 'addition' => 'פתוחים בשישי'],
            'proposed_by' => 'ai',
        ]);

        $sent = null;
        $mcp = Mockery::mock(McpClient::class);
        // The page was edited by the customer AFTER the proposal was made.
        $mcp->shouldReceive('callTool')->withArgs(fn ($s, string $tool) => $tool === 'wp_content_get')
            ->andReturn(['content' => []]);
        $mcp->shouldReceive('textContent')->andReturn((string) json_encode([
            'id' => 12, 'title' => 'דף הבית', 'content' => '<p>תוכן שנכתב אחרי ההצעה</p>',
            'status' => 'publish', 'type' => 'page',
        ]));
        $mcp->shouldReceive('callTool')->withArgs(function ($s, string $tool, array $params = []) use (&$sent) {
            if ($tool === 'wp_content_update') {
                $sent = $params;
            }

            return $tool === 'wp_content_update';
        })->andReturn(['content' => []]);

        $reply = Mockery::mock(AgentReply::class);
        $reply->shouldReceive('send')->zeroOrMoreTimes();

        (new ContentChangeRunner($mcp, $reply))->run($action);

        // The newer content survives; the approved text is appended to it.
        $this->assertStringContainsString('תוכן שנכתב אחרי ההצעה', $sent['content']);
        $this->assertStringContainsString('פתוחים בשישי', $sent['content']);
        $this->assertDatabaseHas('site_events', ['site_id' => $site->id, 'type' => 'content_change']);
    }

    public function test_a_page_that_stopped_being_published_is_never_edited(): void
    {
        // The page was trashed/unpublished between proposal and approval —
        // editing it would mark the action done and tell the customer their
        // text is live on a page nobody can see.
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site1.co.il/wp-json/md-agent/v1/mcp',
        ]);

        $action = PendingAction::create([
            'type' => 'content_change',
            'status' => ActionStatus::Approved,
            'summary' => 'הוספת שעות פתיחה',
            'payload' => ['site_id' => $site->id, 'page_id' => 12, 'page_title' => 'דף הבית', 'addition' => 'פתוחים בשישי'],
            'proposed_by' => 'ai',
        ]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->with(Mockery::any(), 'wp_content_get', Mockery::any())->andReturn(['content' => []]);
        $mcp->shouldReceive('textContent')->andReturn((string) json_encode([
            'id' => 12, 'title' => 'דף הבית', 'content' => '<p>x</p>', 'status' => 'draft', 'type' => 'page',
        ]));
        $mcp->shouldNotReceive('callTool')->with(Mockery::any(), 'wp_content_update', Mockery::any());

        $reply = Mockery::mock(AgentReply::class);
        $reply->shouldNotReceive('send');

        $this->expectException(\RuntimeException::class);

        (new ContentChangeRunner($mcp, $reply))->run($action);
    }

    public function test_a_content_change_can_never_get_a_standing_approval(): void
    {
        // Customer-visible content must be reviewed every single time.
        $this->assertNull(ApprovalGate::standingKeyFor('content_change', ['site_id' => 1, 'page_id' => 2]));
    }
}
