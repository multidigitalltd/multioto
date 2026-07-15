<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\InvestigateTicketJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Agent\SiteAgent;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class InvestigateTicketTest extends TestCase
{
    use RefreshDatabase;

    private function connectedCustomer(): Customer
    {
        $customer = Customer::factory()->create();
        Site::factory()->create([
            'customer_id' => $customer->id,
            'domain' => 'shop.example.com',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://shop.example.com/wp-json/md-agent/v1/mcp',
            'mcp_secret' => 's',
        ]);

        return $customer;
    }

    private function ticketFor(Customer $customer): Ticket
    {
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'האתר איטי',
            'status' => TicketStatus::Open,
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'האתר נטען לאט מאוד מאתמול',
            'author' => MessageAuthor::Customer,
        ]);

        return $ticket;
    }

    public function test_it_posts_the_agent_finding_as_an_internal_note(): void
    {
        $ticket = $this->ticketFor($this->connectedCustomer());

        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldReceive('investigate')->once()
            ->with(Mockery::type(Site::class), Mockery::type('string'))
            ->andReturn('נמצא תוסף קאש כבוי. מומלץ להפעיל מחדש את המטמון.');
        $this->app->instance(SiteAgent::class, $agent);

        InvestigateTicketJob::dispatchSync($ticket->id);

        $note = $ticket->messages()->where('channel', MessageChannel::InternalNote)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertSame(MessageAuthor::Ai, $note->author);
        $this->assertStringContainsString('בדיקת סוכן AI', $note->body);
        $this->assertStringContainsString('להפעיל מחדש את המטמון', $note->body);
    }

    public function test_it_investigates_the_site_the_ticket_names_when_several_are_connected(): void
    {
        $customer = Customer::factory()->create();
        foreach (['alpha.example.com', 'beta.example.com'] as $domain) {
            Site::factory()->create([
                'customer_id' => $customer->id, 'domain' => $domain,
                'mcp_enabled' => true, 'mcp_endpoint' => "https://{$domain}/wp-json/md-agent/v1/mcp", 'mcp_secret' => 's',
            ]);
        }
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'תקלה ב-beta.example.com', 'status' => TicketStatus::Open,
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'body' => 'האתר beta.example.com לא נטען', 'author' => MessageAuthor::Customer,
        ]);

        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldReceive('investigate')->once()
            ->withArgs(fn (Site $s, string $goal): bool => $s->domain === 'beta.example.com')
            ->andReturn('נבדק beta.');
        $this->app->instance(SiteAgent::class, $agent);

        InvestigateTicketJob::dispatchSync($ticket->id);

        $note = $ticket->messages()->where('channel', MessageChannel::InternalNote)->latest('id')->first();
        $this->assertStringContainsString('beta.example.com', $note->body);
    }

    public function test_it_asks_for_a_site_when_several_are_connected_and_none_is_named(): void
    {
        $customer = Customer::factory()->create();
        foreach (['alpha.example.com', 'beta.example.com'] as $domain) {
            Site::factory()->create([
                'customer_id' => $customer->id, 'domain' => $domain,
                'mcp_enabled' => true, 'mcp_endpoint' => "https://{$domain}/wp-json/md-agent/v1/mcp", 'mcp_secret' => 's',
            ]);
        }
        $ticket = $this->ticketFor($customer); // subject/body name no specific site

        // With no clear match the agent must NOT be called on a guessed site.
        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldNotReceive('investigate');
        $this->app->instance(SiteAgent::class, $agent);

        InvestigateTicketJob::dispatchSync($ticket->id);

        $note = $ticket->messages()->where('channel', MessageChannel::InternalNote)->latest('id')->first();
        $this->assertStringContainsString('כמה אתרים מחוברים', $note->body);
    }

    public function test_it_notes_when_the_customer_has_no_connected_site(): void
    {
        $customer = Customer::factory()->create(); // no site
        $ticket = $this->ticketFor($customer);

        // The agent must never be called when there's nothing to investigate.
        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldNotReceive('investigate');
        $this->app->instance(SiteAgent::class, $agent);

        InvestigateTicketJob::dispatchSync($ticket->id);

        $note = $ticket->messages()->where('channel', MessageChannel::InternalNote)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('אין אתר מחובר', $note->body);
    }

    public function test_a_new_ticket_auto_investigates_only_when_enabled(): void
    {
        config(['agent.auto_investigate_tickets' => true]);
        Queue::fake([InvestigateTicketJob::class]);
        $customer = $this->connectedCustomer();

        app(TicketIntake::class)->recordInbound(
            TicketChannel::Email,
            MessageChannel::Email,
            $customer,
            'האתר לא נטען',
            externalMessageId: 'm-auto-1',
        );

        Queue::assertPushed(InvestigateTicketJob::class);
    }

    public function test_a_new_ticket_does_not_auto_investigate_when_disabled(): void
    {
        config(['agent.auto_investigate_tickets' => false]);
        Queue::fake([InvestigateTicketJob::class]);
        $customer = $this->connectedCustomer();

        app(TicketIntake::class)->recordInbound(
            TicketChannel::Email,
            MessageChannel::Email,
            $customer,
            'האתר לא נטען',
            externalMessageId: 'm-auto-2',
        );

        Queue::assertNotPushed(InvestigateTicketJob::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
