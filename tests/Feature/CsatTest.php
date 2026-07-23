<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CsatTest extends TestCase
{
    use RefreshDatabase;

    private function resolvedTicket(): Ticket
    {
        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        return Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'בעיה באתר',
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function test_the_resolved_message_invites_the_customer_to_rate_and_marks_it_requested(): void
    {
        Mail::fake();
        $ticket = $this->resolvedTicket();

        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.resolved');

        $body = (string) $ticket->messages()->where('direction', MessageDirection::Outbound)->value('body');
        $this->assertStringContainsString('/support/rate/'.$ticket->id, $body);
        $this->assertNotNull($ticket->fresh()->csat_requested_at);
    }

    public function test_csat_can_be_disabled(): void
    {
        Mail::fake();
        config(['billing.support.csat.enabled' => false]);
        $ticket = $this->resolvedTicket();

        SendTicketNotificationJob::dispatchSync($ticket->id, 'ticket.resolved');

        $body = (string) $ticket->messages()->where('direction', MessageDirection::Outbound)->value('body');
        $this->assertStringNotContainsString('/support/rate/', $body);
        $this->assertNull($ticket->fresh()->csat_requested_at);
    }

    public function test_an_unsigned_rating_link_is_rejected(): void
    {
        $ticket = $this->resolvedTicket();

        $this->get('/support/rate/'.$ticket->id)->assertForbidden();
    }

    public function test_a_signed_link_shows_the_form_and_records_a_rating(): void
    {
        $ticket = $this->resolvedTicket();

        $show = URL::temporarySignedRoute('csat.show', now()->addDays(30), ['ticket' => $ticket->id]);
        $this->get($show)->assertOk()->assertSee('איך היה השירות');

        $store = URL::temporarySignedRoute('csat.store', now()->addDays(30), ['ticket' => $ticket->id]);
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post($store, ['rating' => 5, 'comment' => 'שירות מעולה'])
            ->assertOk()->assertSee('תודה');

        $ticket->refresh();
        $this->assertSame(5, $ticket->csat_rating);
        $this->assertSame('שירות מעולה', $ticket->csat_comment);
        $this->assertNotNull($ticket->csat_rated_at);
    }

    public function test_an_out_of_range_rating_is_rejected(): void
    {
        $ticket = $this->resolvedTicket();

        $store = URL::temporarySignedRoute('csat.store', now()->addDays(30), ['ticket' => $ticket->id]);
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from($store)
            ->post($store, ['rating' => 6])
            ->assertSessionHasErrors('rating');

        $this->assertNull($ticket->fresh()->csat_rating);
    }
}
