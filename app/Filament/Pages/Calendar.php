<?php

namespace App\Filament\Pages;

use App\Enums\ServiceMode;
use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Models\ServiceException;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\HebrewDate;
use App\Services\Calendar\ShabbatClock;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;

/**
 * לוח שנה — תצוגת חודש שמראה את המשימות (לפי תאריך יעד), לצד תאריך עברי ולועזי,
 * הצללת שבתות וחגים (עם זמני כניסה/צאת) וימי שירות מיוחדים (מתכונת מצומצמת /
 * דחוף בלבד). מקום אחד לראות את עומס העבודה של הצוות בהקשר של לוח השנה העברי.
 *
 * המשימות וימי השירות נטענים בשתי שאילתות לכל החודש הנראה, והתאריך העברי וזמני
 * השבת מחושבים מקומית (תוסף calendar) — בלי קריאות חיצוניות. אפשר גם להוסיף
 * משימה או יום שירות ישירות מתוך יום בלוח (כפתור ההוספה על התא).
 */
class Calendar extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

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

    /** Today's date — the default for the header "add" button. */
    public function getTodayProperty(): string
    {
        return Carbon::now()->toDateString();
    }

    /**
     * Add a task or mark a special service day straight from the calendar. The
     * clicked day (passed as a mount argument) prefills the dates; a radio at the
     * top switches the form between the two, so one button on a day cell covers
     * both. Creates the same records the dedicated screens do.
     */
    public function quickAddAction(): Action
    {
        return Action::make('quickAdd')
            ->label('הוספה ללוח')
            ->modalHeading('הוספה ללוח')
            ->modalWidth(MaxWidth::Large)
            ->modalSubmitActionLabel('הוסף')
            ->mountUsing(function (Forms\Form $form, array $arguments): void {
                $date = Carbon::parse($arguments['date'] ?? Carbon::now()->toDateString());

                $form->fill([
                    'type' => 'task',
                    'due_at' => $date->copy()->setTime(9, 0)->toDateTimeString(),
                    'priority' => TicketPriority::Normal->value,
                    'mode' => ServiceMode::Reduced->value,
                    'starts_on' => $date->toDateString(),
                    'ends_on' => $date->toDateString(),
                ]);
            })
            ->form([
                Forms\Components\Radio::make('type')
                    ->label('מה להוסיף?')
                    ->options(['task' => 'משימה', 'service' => 'יום שירות מיוחד'])
                    ->default('task')->required()->live()->inline()->inlineLabel(false)->columnSpanFull(),

                // --- Task ---
                Forms\Components\TextInput::make('title')
                    ->label('כותרת')->required()->maxLength(255)->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('type') === 'task'),
                Forms\Components\DateTimePicker::make('due_at')
                    ->label('מועד יעד')->seconds(false)->native(false)->required()
                    ->visible(fn (Get $get): bool => $get('type') === 'task'),
                Forms\Components\Select::make('priority')
                    ->label('עדיפות')->options(TicketPriority::class)->default(TicketPriority::Normal)
                    ->visible(fn (Get $get): bool => $get('type') === 'task'),
                Forms\Components\Select::make('assignees')
                    ->label('אחראים')->multiple()->searchable()->preload()
                    ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('ללא שיוך')->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('type') === 'task'),

                // --- Special service day ---
                Forms\Components\Select::make('mode')
                    ->label('מצב')->options(ServiceMode::class)->default(ServiceMode::Reduced)->required()->native(false)
                    ->visible(fn (Get $get): bool => $get('type') === 'service'),
                Forms\Components\DatePicker::make('starts_on')
                    ->label('מתאריך')->native(false)->required()->live()
                    ->afterStateUpdated(fn ($state, Forms\Set $set, Get $get) => filled($state) && blank($get('ends_on')) ? $set('ends_on', $state) : null)
                    ->visible(fn (Get $get): bool => $get('type') === 'service'),
                Forms\Components\DatePicker::make('ends_on')
                    ->label('עד תאריך (כולל)')->native(false)->required()->afterOrEqual('starts_on')
                    ->visible(fn (Get $get): bool => $get('type') === 'service'),
                Forms\Components\TextInput::make('note')
                    ->label('הערה (אופציונלי)')->maxLength(255)->columnSpanFull()
                    ->helperText('הערה פנימית שתעזור לסוכן לנסח — לא נשלחת כלשונה ללקוח.')
                    ->visible(fn (Get $get): bool => $get('type') === 'service'),
            ])
            ->action(function (array $data): void {
                if (($data['type'] ?? 'task') === 'service') {
                    ServiceException::create([
                        'starts_on' => $data['starts_on'],
                        'ends_on' => $data['ends_on'] ?: $data['starts_on'],
                        'mode' => $data['mode'],
                        'note' => $data['note'] ?? null,
                    ]);

                    Notification::make()->title('יום השירות נוסף ללוח')->success()->send();

                    return;
                }

                $task = Task::create([
                    'title' => $data['title'],
                    'due_at' => $data['due_at'] ?? null,
                    'status' => TaskStatus::Open,
                    'priority' => $data['priority'] ?? TicketPriority::Normal,
                ]);

                if (! empty($data['assignees'])) {
                    $task->assignees()->sync($data['assignees']);
                }

                Notification::make()->title('המשימה נוספה ללוח')->success()->send();
            });
    }
}
