<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_tickets_are_ordered_newest_first(): void
    {
        $customer = Customer::factory()->create();

        $older = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'ישן', 'status' => TicketStatus::Open,
        ]);
        $newer = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'חדש', 'status' => TicketStatus::Open,
        ]);
        // created_at isn't fillable — set it directly to make the order explicit.
        Ticket::whereKey($older->id)->update(['created_at' => now()->subDays(3)]);
        Ticket::whereKey($newer->id)->update(['created_at' => now()]);

        $ordered = $customer->recentTickets()->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ordered);
    }
}
