<?php

namespace Tests\Feature;

use App\Enums\ServiceMode;
use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Filament\Pages\Calendar;
use App\Models\ServiceException;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\ShabbatClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fix "now" to a Wednesday in Tammuz 5786 so the visible month is July 2026.
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00', 'Asia/Jerusalem'));
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_calendar_shows_open_tasks_service_days_and_hebrew_dates(): void
    {
        Task::create(['title' => 'לבדוק גיבוי אתר', 'status' => TaskStatus::Open, 'due_at' => Carbon::parse('2026-07-20 12:00')]);
        Task::create(['title' => 'לעדכן תוסף אבטחה', 'status' => TaskStatus::InProgress, 'due_at' => Carbon::parse('2026-07-21 10:00')]);
        // A done task, a dateless task, and a task in another month must NOT show.
        Task::create(['title' => 'משימה שהושלמה', 'status' => TaskStatus::Done, 'due_at' => Carbon::parse('2026-07-22 10:00')]);
        Task::create(['title' => 'משימה ללא תאריך', 'status' => TaskStatus::Open]);
        Task::create(['title' => 'משימה בחודש אחר', 'status' => TaskStatus::Open, 'due_at' => Carbon::parse('2026-09-10 10:00')]);

        // A reduced-capacity span; the internal note must never reach the view.
        ServiceException::create([
            'starts_on' => '2026-07-22', 'ends_on' => '2026-07-23',
            'mode' => ServiceMode::Reduced, 'note' => 'סוד פנימי',
        ]);

        Livewire::test(Calendar::class)
            ->assertOk()
            ->assertSeeText('יולי 2026')          // Gregorian heading
            ->assertSeeText('תשפ״ו')               // Hebrew year in the heading
            ->assertSeeText('לבדוק גיבוי אתר')     // an open task, on its due day
            ->assertSeeText('לעדכן תוסף אבטחה')    // an in-progress task
            ->assertSeeText('שבת')                 // Shabbat cells are labelled
            ->assertSeeText('מצומצמת')             // the service-day badge
            ->assertDontSeeText('סוד פנימי')       // the internal note stays internal
            ->assertDontSeeText('משימה שהושלמה')   // done tasks are excluded
            ->assertDontSeeText('משימה ללא תאריך') // dateless tasks are not placed
            ->assertDontSeeText('משימה בחודש אחר'); // out-of-range tasks are not loaded
    }

    public function test_quick_add_creates_a_task_on_the_clicked_day(): void
    {
        Livewire::test(Calendar::class)
            ->callAction('quickAdd', data: [
                'type' => 'task',
                'title' => 'להתקשר ללקוח',
                'due_at' => '2026-07-20 09:00',
                'priority' => TicketPriority::Normal->value,
            ], arguments: ['date' => '2026-07-20'])
            ->assertHasNoActionErrors();

        $task = Task::where('title', 'להתקשר ללקוח')->first();
        $this->assertNotNull($task);
        $this->assertSame('2026-07-20', $task->due_at->toDateString());
        $this->assertSame(TaskStatus::Open, $task->status);
    }

    public function test_quick_add_requires_a_title_for_a_task(): void
    {
        Livewire::test(Calendar::class)
            ->callAction('quickAdd', data: ['type' => 'task', 'title' => ''], arguments: ['date' => '2026-07-20'])
            ->assertHasActionErrors(['title' => ['required']]);

        $this->assertSame(0, Task::count());
    }

    public function test_quick_add_can_mark_a_special_service_day(): void
    {
        Livewire::test(Calendar::class)
            ->callAction('quickAdd', data: [
                'type' => 'service',
                'mode' => ServiceMode::UrgentOnly->value,
                'starts_on' => '2026-07-24',
                'ends_on' => '2026-07-24',
                'note' => 'עומס פנימי',
            ], arguments: ['date' => '2026-07-24'])
            ->assertHasNoActionErrors();

        $exception = ServiceException::first();
        $this->assertNotNull($exception);
        $this->assertSame(ServiceMode::UrgentOnly, $exception->mode);
        $this->assertSame('2026-07-24', $exception->starts_on->toDateString());
        $this->assertSame('עומס פנימי', $exception->note);
    }

    public function test_a_service_day_can_be_edited_from_the_calendar(): void
    {
        $exception = ServiceException::create([
            'starts_on' => '2026-07-22', 'ends_on' => '2026-07-22', 'mode' => ServiceMode::Reduced,
        ]);

        Livewire::test(Calendar::class)
            ->callAction('editServiceDay', data: [
                'mode' => ServiceMode::UrgentOnly->value,
                'starts_on' => '2026-07-22',
                'ends_on' => '2026-07-23',
                'note' => 'הורחב',
            ], arguments: ['id' => $exception->id])
            ->assertHasNoActionErrors();

        $exception->refresh();
        $this->assertSame(ServiceMode::UrgentOnly, $exception->mode);
        $this->assertSame('2026-07-23', $exception->ends_on->toDateString());
        $this->assertSame('הורחב', $exception->note);
    }

    public function test_a_service_day_can_be_deleted_from_the_calendar(): void
    {
        $exception = ServiceException::create([
            'starts_on' => '2026-07-22', 'ends_on' => '2026-07-22', 'mode' => ServiceMode::Reduced,
        ]);

        Livewire::test(Calendar::class)->call('deleteServiceDay', $exception->id);

        $this->assertModelMissing($exception);
    }

    public function test_month_navigation_moves_forward_back_and_home(): void
    {
        Livewire::test(Calendar::class)
            ->assertSet('year', 2026)->assertSet('month', 7)
            ->call('previousMonth')->assertSet('month', 6)
            ->call('nextMonth')->call('nextMonth')->assertSet('month', 8)
            ->call('goToday')->assertSet('month', 7)->assertSet('year', 2026);
    }

    public function test_month_navigation_rolls_over_the_year(): void
    {
        Livewire::test(Calendar::class)
            ->set('year', 2026)->set('month', 1)
            ->call('previousMonth')
            ->assertSet('year', 2025)->assertSet('month', 12);
    }

    public function test_rest_day_details_describe_shabbat_but_not_a_weekday(): void
    {
        $clock = app(ShabbatClock::class);

        // A plain Wednesday is not a rest day.
        $this->assertNull($clock->restDay(Carbon::parse('2026-07-15', 'Asia/Jerusalem')));

        // Saturday 2026-07-18 is a standalone Shabbat: it both opens and closes
        // its own one-day window, candle lighting falls on the Friday eve, and
        // havdalah on the Saturday night.
        $rest = $clock->restDay(Carbon::parse('2026-07-18', 'Asia/Jerusalem'));
        $this->assertNotNull($rest);
        $this->assertSame('שבת', $rest['label']);
        $this->assertTrue($rest['first']);
        $this->assertTrue($rest['last']);
        $this->assertTrue($rest['entry']->isFriday());
        $this->assertTrue($rest['exit']->isSaturday());
        $this->assertTrue($rest['entry']->lessThan($rest['exit']));
    }

    public function test_each_day_of_a_chag_shabbat_run_keeps_its_own_label(): void
    {
        // Shavuot 2026 falls on Friday 2026-05-22 and runs straight into Shabbat
        // on Saturday 2026-05-23 — one shared entry/exit window, but each day is
        // labelled for what it is (not both "שבועות" nor both "שבת").
        $clock = app(ShabbatClock::class);

        $friday = $clock->restDay(Carbon::parse('2026-05-22', 'Asia/Jerusalem'));
        $saturday = $clock->restDay(Carbon::parse('2026-05-23', 'Asia/Jerusalem'));

        $this->assertSame('שבועות', $friday['label']);
        $this->assertTrue($friday['first']);
        $this->assertFalse($friday['last']);

        $this->assertSame('שבת', $saturday['label']);
        $this->assertFalse($saturday['first']);
        $this->assertTrue($saturday['last']);

        // The two days share one window: candle lighting on the eve, havdalah at the end.
        $this->assertEquals($friday['entry'], $saturday['entry']);
        $this->assertEquals($friday['exit'], $saturday['exit']);
    }

    public function test_rest_day_ignores_the_automation_toggle(): void
    {
        // The base TestCase disables the automation halt; the calendar still
        // describes the day (restDay is about the date, not the pause switch).
        config(['billing.shabbat.block_automations' => false]);

        $this->assertNotNull(app(ShabbatClock::class)->restDay(Carbon::parse('2026-07-18', 'Asia/Jerusalem')));
    }
}
