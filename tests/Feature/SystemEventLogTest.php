<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemEventLog;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The event log is where a report goes when nobody could be emailed. What it
 * cannot show, nobody can read.
 */
class SystemEventLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whole_context_is_reachable_even_when_it_is_long(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        // A morning with more findings than fit in a truncated column: the ids
        // are exactly the part that gets cut off.
        $ids = collect(range(1, 40))->map(fn (int $n): string => "חיוב #{$n} · ₪118.00")->implode("\n");
        SystemLog::record('error', 'billing', 'בדיקת שלמות כספית מצאה חריגות', [
            'findings' => [['title' => 'חיובים שהצליחו ללא חשבונית (40)', 'detail' => $ids]],
        ]);

        $log = SystemLog::sole();

        Livewire::test(SystemEventLog::class)
            ->assertTableActionVisible('context', $log)
            // The last id is far past the column's limit — opening the entry
            // must still hand it over.
            ->mountTableAction('context', $log)
            ->assertSee('חיוב #40');
    }

    public function test_an_entry_without_context_offers_nothing_to_open(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        SystemLog::record('info', 'ai', 'סתם אירוע');

        Livewire::test(SystemEventLog::class)
            ->assertTableActionHidden('context', SystemLog::sole());
    }
}
