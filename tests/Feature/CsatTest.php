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

    public function test_a_reopened_ticket_rejects_a_stale_rating(): void
    {
        $ticket = $this->resolvedTicket();
        // Customer replied before rating → the ticket was reopened.
        $ticket->update(['status' => TicketStatus::Open]);

        $store = URL::temporarySignedRoute('csat.store', now()->addDays(30), ['ticket' => $ticket->id]);
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post($store, ['rating' => 5])
            ->assertOk()->assertSee('נפתחה מחדש');

        $this->assertNull($ticket->fresh()->csat_rating);
    }

    public function test_reopening_a_ticket_resets_the_csat_cycle(): void
    {
        $ticket = $this->resolvedTicket();
        $ticket->forceFill(['csat_requested_at' => now(), 'csat_rating' => 4, 'csat_rated_at' => now()])->save();

        $ticket->update(['status' => TicketStatus::Open]); // reopened

        $ticket->refresh();
        $this->assertNull($ticket->csat_rating);
        $this->assertNull($ticket->csat_requested_at);
        $this->assertNull($ticket->csat_rated_at);
    }

    public function test_the_form_action_does_not_outlive_the_original_link(): void
    {
        $ticket = $this->resolvedTicket();

        // A show link that expires in 2 minutes must yield a POST action that
        // expires at the same moment — not a fresh 30-day window.
        $show = URL::temporarySignedRoute('csat.show', now()->addMinutes(2), ['ticket' => $ticket->id]);
        $html = $this->get($show)->assertOk()->getContent();

        preg_match('/action="([^"]+)"/', $html, $m);
        parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $query);

        // Within a minute of the original 2-minute expiry, never ~30 days out.
        $this->assertLessThanOrEqual(now()->addMinutes(3)->timestamp, (int) $query['expires']);
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
