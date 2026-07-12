<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Pages\ImportTickets;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Import\TicketImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TicketImportTest extends TestCase
{
    use RefreshDatabase;

    /** Rows keyed by the exporter's real (Hebrew) headers. */
    private function rows(): array
    {
        return [
            ['ID' => '1366', 'נושא' => 'אתר שארפן', 'כתובת דוא"ל' => 'client@example.co.il', 'Status' => 'טופל / הושלם', 'עדיפות' => 'רגיל / לטיפול בהקדם', 'Date Closed' => '2023-08-27 18:51:08', 'תוכן' => 'האתר לא נטען, אנא בדקו'],
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

    public function test_it_creates_an_opening_message_from_the_body_or_subject(): void
    {
        (new TicketImporter)->import($this->rows());

        // Ticket with a content column → that content becomes the opening message.
        $first = Ticket::with('messages')->find(1366);
        $this->assertCount(1, $first->messages);
        $opening = $first->messages->first();
        $this->assertSame('האתר לא נטען, אנא בדקו', $opening->body);
        $this->assertSame(MessageDirection::Inbound, $opening->direction);
        $this->assertSame(MessageAuthor::Customer, $opening->author);
        $this->assertSame('2023-08-27', $opening->created_at->toDateString());

        // Ticket without a body column → the subject is used so the view isn't empty.
        $second = Ticket::with('messages')->find(1367);
        $this->assertCount(1, $second->messages);
        $this->assertSame($second->subject, $second->messages->first()->body);
    }

    public function test_the_import_sends_no_mail_to_customers(): void
    {
        Mail::fake();
        Customer::factory()->create(['email' => 'client@example.co.il']);

        (new TicketImporter)->import($this->rows());

        Mail::assertNothingSent();
    }

    public function test_delete_imported_removes_legacy_tickets_and_their_messages(): void
    {
        (new TicketImporter)->import($this->rows());

        // A ticket created in the app itself must survive the cleanup.
        $native = Ticket::create([
            'channel' => TicketChannel::Manual,
            'subject' => 'כרטיס מקומי',
            'status' => TicketStatus::Open,
        ]);

        $deleted = (new TicketImporter)->deleteImported();

        $this->assertSame(2, $deleted);
        $this->assertNull(Ticket::find(1366));
        $this->assertNull(Ticket::find(1367));
        $this->assertDatabaseCount('ticket_messages', 0); // cascaded away
        $this->assertNotNull(Ticket::find($native->id)); // native ticket untouched
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
