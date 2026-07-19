<?php

namespace App\Filament\Pages;

use App\Models\ServiceException;
use App\Models\Task;
use App\Services\Calendar\HebrewDate;
use App\Services\Calendar\ShabbatClock;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * לוח שנה — תצוגת חודש שמראה את המשימות (לפי תאריך יעד), לצד תאריך עברי ולועזי,
 * הצללת שבתות וחגים (עם זמני כניסה/צאת) וימי שירות מיוחדים (מתכונת מצומצמת /
 * דחוף בלבד). מקום אחד לראות את עומס העבודה של הצוות בהקשר של לוח השנה העברי.
 *
 * קריאה בלבד: הנתונים נטענים בשתי שאילתות לכל החודש הנראה (משימות + ימי שירות),
 * והתאריך העברי וזמני השבת מחושבים מקומית (תוסף calendar) — בלי קריאות חיצוניות.
 */
class Calendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'לוח שנה';

    protected static ?string $title = 'לוח שנה';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.calendar';

    /** Gregorian months in Hebrew — for the header, without depending on locale files. */
    private const GREGORIAN_MONTHS = [
        1 => 'ינואר', 2 => 'פברואר', 3 => 'מרץ', 4 => 'אפריל', 5 => 'מאי', 6 => 'יוני',
        7 => 'יולי', 8 => 'אוגוסט', 9 => 'ספטמבר', 10 => 'אוקטובר', 11 => 'נובמבר', 12 => 'דצמבר',
    ];

    /** Hebrew week starts on Sunday. */
    public const WEEKDAYS = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];

    public int $year;

    public int $month;

    public function mount(): void
    {
        $now = Carbon::now();
        $this->year = $now->year;
        $this->month = $now->month;
    }

    public function previousMonth(): void
    {
        $this->shiftMonth(-1);
    }

    public function nextMonth(): void
    {
        $this->shiftMonth(1);
    }

    public function goToday(): void
    {
        $now = Carbon::now();
        $this->year = $now->year;
        $this->month = $now->month;
    }

    private function shiftMonth(int $by): void
    {
        $target = Carbon::create($this->year, $this->month, 1)->addMonths($by);
        $this->year = $target->year;
        $this->month = $target->month;
    }

    /** The month heading — Gregorian ("יולי 2026") plus the Hebrew month range. */
    public function getMonthTitleProperty(): string
    {
        $first = Carbon::create($this->year, $this->month, 1);
        $last = $first->copy()->endOfMonth();

        $gregorian = (self::GREGORIAN_MONTHS[$this->month] ?? '').' '.$this->year;
        $h1 = HebrewDate::monthYear($first);
        $h2 = HebrewDate::monthYear($last);
        $hebrew = $h1 === $h2 ? $h1 : "{$h1} – {$h2}";

        return "{$gregorian} · {$hebrew}";
    }

    /**
     * The visible month as weeks of day cells (leading/trailing days fill the
     * first and last weeks). Two queries for the whole grid; Hebrew date and
     * Shabbat times are computed locally per day.
     *
     * @return list<list<array<string, mixed>>>
     */
    public function getWeeksProperty(): array
    {
        $first = Carbon::create($this->year, $this->month, 1)->startOfDay();
        $gridStart = $first->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $first->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $tasksByDay = Task::query()->open()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$gridStart->copy()->startOfDay(), $gridEnd->copy()->endOfDay()])
            ->with('assignees:id,name')
            ->orderBy('due_at')
            ->get()
            ->groupBy(fn (Task $task): string => $task->due_at->toDateString());

        $exceptions = ServiceException::query()
            ->whereDate('starts_on', '<=', $gridEnd->toDateString())
            ->whereDate('ends_on', '>=', $gridStart->toDateString())
            ->get();

        $clock = app(ShabbatClock::class);
        $today = Carbon::now()->toDateString();

        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor <= $gridEnd) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $key = $cursor->toDateString();
                $rest = $clock->restDay($cursor);
                // Candle lighting is shown on the eve — the day before a rest run.
                $eveOf = $rest === null ? $clock->restDay($cursor->copy()->addDay()) : null;

                $week[] = [
                    'date' => $cursor->copy(),
                    'inMonth' => $cursor->month === $this->month,
                    'isToday' => $key === $today,
                    'gregorianDay' => $cursor->day,
                    'hebrewDay' => HebrewDate::day($cursor),
                    'hebrewMonth' => HebrewDate::dayOfMonth($cursor) === 1 ? HebrewDate::month($cursor) : null,
                    'rest' => $rest,
                    'candle' => ($eveOf !== null && $eveOf['first']) ? $eveOf['entry'] : null,
                    'service' => $exceptions->first(
                        fn (ServiceException $e): bool => $cursor->betweenIncluded($e->starts_on, $e->ends_on)
                    ),
                    'tasks' => $tasksByDay->get($key, collect()),
                ];

                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }
}
