<?php

namespace Tests\Feature;

use App\Enums\ServiceMode;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Customer;
use App\Models\ServiceException;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Notifications\TemplateEngine;
use App\Services\Support\ServiceStatus;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ServiceExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_status_finds_the_active_day_and_gives_agent_guidance(): void
    {
        $status = app(ServiceStatus::class);
        $this->assertNull($status->current());

        ServiceException::create([
            'starts_on' => now()->subDay(), 'ends_on' => now()->addDay(),
            'mode' => ServiceMode::UrgentOnly, 'note' => 'יום עומס',
        ]);

        $this->assertSame(ServiceMode::UrgentOnly, $status->current()->mode);
        $this->assertStringContainsString('דחופות בלבד', $status->agentGuidance());
        $this->assertStringContainsString('יום עומס', $status->agentGuidance());
    }

    public function test_a_span_that_does_not_cover_today_is_not_active(): void
    {
        ServiceException::create([
            'starts_on' => now()->addWeek(), 'ends_on' => now()->addWeeks(2),
            'mode' => ServiceMode::Reduced,
        ]);

        $this->assertNull(app(ServiceStatus::class)->current());
    }

    public function test_the_template_ack_appends_a_notice_when_the_ai_is_off(): void
    {
        // Default config: dynamic AI ack OFF → the fixed template is used, and it
        // must still carry the reduced-capacity notice on a marked day.
        config([
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);

        ServiceException::create(['starts_on' => now(), 'ends_on' => now(), 'mode' => ServiceMode::UrgentOnly]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], 'דחופות בלבד'));
    }

    public function test_the_new_ticket_ack_tells_the_agent_about_a_reduced_capacity_day(): void
    {
        config([
            'billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true,
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);

        ServiceException::create([
            'starts_on' => now(), 'ends_on' => now(),
            'mode' => ServiceMode::Reduced,
        ]);

        $customer = Customer::factory()->create(['name' => 'דנה']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        // The agent is told about the reduced-capacity day via the system prompt.
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()
            ->with(Mockery::on(fn (string $system): bool => str_contains($system, 'מתכונת מצומצמת')), Mockery::any(), Mockery::any())
            ->andReturn(['message' => 'היי דנה, קיבלנו את פנייתך.']);

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText'));
    }
}
