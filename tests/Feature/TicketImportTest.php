<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Pages\ImportTickets;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Import\TicketImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketImportTest extends TestCase
{
    use RefreshDatabase;

    /** Rows keyed by the exporter's real (Hebrew) headers. */
    private function rows(): array
    {
        return [
            ['ID' => '1366', 'נושא' => 'אתר שארפן', 'כתובת דוא"ל' => 'client@example.co.il', 'Status' => 'טופל / הושלם', 'עדיפות' => 'רגיל / לטיפול בהקדם', 'Date Closed' => '2023-08-27 18:51:08'],
            ['ID' => '1367', 'נושא' => '', 'כתובת דוא"ל' => 'unknown@nowhere.test', 'Status' => 'ממתין לתשובתך', 'עדיפות' => 'sos - אתר מושבת', 'Date Closed' => '2023-09-01 10:00:00'],
        ];
    }

    public function test_it_preserves_ids_matches_customers_and_maps_fields(): void
    {
        $customer = Customer::factory()->create(['email' => 'client@example.co.il']);

        $result = (new TicketImporter)->import($this->rows());

        $this->assertSame(2, $result->imported);
        $this->assertSame(1, $result->matched);

        $first = Ticket::find(1366);
        $this->assertNotNull($first); // original id preserved
        $this->assertSame($customer->id, $first->customer_id);
        $this->assertSame(TicketStatus::Closed, $first->status);
        $this->assertSame(TicketPriority::Normal, $first->priority);
        $this->assertSame('2023-08-27', $first->created_at->toDateString());
        $this->assertNotNull($first->resolved_at); // closed → resolved date set
        $this->assertSame('legacy-1366', $first->external_thread_ref);

        $second = Ticket::find(1367);
        $this->assertNull($second->customer_id); // unmatched email
        $this->assertSame(TicketStatus::Pending, $second->status);
        $this->assertSame(TicketPriority::Urgent, $second->priority);
        $this->assertStringContainsString('1367', $second->subject); // empty-subject fallback
        $this->assertNull($second->resolved_at); // not closed
    }

    public function test_new_tickets_continue_the_numbering_after_import(): void
    {
        (new TicketImporter)->import($this->rows());

        // A brand-new ticket must get the next number after the highest imported id.
        $new = Ticket::create([
            'channel' => TicketChannel::Manual,
            'subject' => 'כרטיס חדש',
            'status' => TicketStatus::Open,
        ]);

        $this->assertSame(1368, $new->id);
    }

    public function test_re_importing_skips_existing_ids(): void
    {
        (new TicketImporter)->import($this->rows());
        $result = (new TicketImporter)->import($this->rows());

        $this->assertSame(0, $result->imported);
        $this->assertSame(2, $result->skippedCount());
        $this->assertSame(2, Ticket::count());
    }

    public function test_the_import_page_renders(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ImportTickets::class)->assertOk();
    }
}
