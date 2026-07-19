<x-filament-panels::page>
    {{--
        Styling lives in a scoped <style> block rather than Tailwind utility
        classes: the Filament panel ships a precompiled stylesheet purged to the
        utilities Filament itself uses, so app-specific colours (the Shabbat /
        service-day tints) and arbitrary sizes would be dropped in production.
        Literal CSS here is served verbatim and is theme-aware via Filament's
        `.dark` class on <html>.
    --}}
    <style>
        .cal-wrap { overflow-x: auto; }
        .cal-inner { min-width: 46rem; border: 1px solid rgb(229 231 235); border-radius: 0.75rem; overflow: hidden; }
        .dark .cal-inner { border-color: rgb(55 65 81); }

        .cal-head, .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
        .cal-head { background: rgb(249 250 251); border-bottom: 1px solid rgb(229 231 235); }
        .dark .cal-head { background: rgb(31 41 55); border-color: rgb(55 65 81); }
        .cal-head > div { padding: 0.375rem 0.5rem; text-align: center; font-size: 0.75rem; font-weight: 600; color: rgb(75 85 99); }
        .dark .cal-head > div { color: rgb(209 213 219); }

        .cal-cell {
            display: flex; flex-direction: column; gap: 0.25rem;
            min-height: 7.5rem; padding: 0.5rem;
            background: #fff; font-size: 0.8rem;
            border-bottom: 1px solid rgb(243 244 246); border-inline-start: 1px solid rgb(243 244 246);
        }
        .dark .cal-cell { background: rgb(17 24 39); border-color: rgb(31 41 55); }
        .cal-cell--out { opacity: 0.5; }
        .cal-cell--rest { background: #eef2ff; }
        .dark .cal-cell--rest { background: rgba(67, 56, 202, 0.20); }
        .cal-cell--reduced { background: #fffbeb; }
        .dark .cal-cell--reduced { background: rgba(180, 83, 9, 0.20); }
        .cal-cell--urgent { background: #fff1f2; }
        .dark .cal-cell--urgent { background: rgba(190, 18, 60, 0.20); }

        .cal-cell__top { display: flex; align-items: flex-start; justify-content: space-between; }
        .cal-daynum { font-weight: 600; color: rgb(55 65 81); }
        .dark .cal-daynum { color: rgb(229 231 235); }
        .cal-daynum--today {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.5rem; height: 1.5rem; border-radius: 9999px;
            background: rgb(var(--primary-600)); color: #fff;
        }
        .cal-hebday { font-size: 0.75rem; font-weight: 500; color: rgb(156 163 175); }

        .cal-chip { width: fit-content; border-radius: 0.25rem; padding: 0 0.25rem; font-size: 0.625rem; font-weight: 500;
            background: rgb(243 244 246); color: rgb(107 114 128); }
        .dark .cal-chip { background: rgb(31 41 55); color: rgb(156 163 175); }

        .cal-rest { display: flex; flex-direction: column; line-height: 1.2; font-size: 0.6875rem; color: rgb(67 56 202); }
        .dark .cal-rest { color: rgb(165 180 252); }
        .cal-rest__name { font-weight: 600; }
        .cal-candle { line-height: 1.2; font-size: 0.6875rem; color: rgba(79, 70, 229, 0.85); }
        .dark .cal-candle { color: rgba(165, 180, 252, 0.85); }

        .cal-svc { width: fit-content; border-radius: 0.25rem; padding: 0.05rem 0.375rem; font-size: 0.625rem; font-weight: 600; }
        .cal-svc--reduced { background: rgb(254 243 199); color: rgb(146 64 14); }
        .dark .cal-svc--reduced { background: rgba(146, 64, 14, 0.5); color: rgb(253 230 138); }
        .cal-svc--urgent { background: rgb(255 228 230); color: rgb(159 18 57); }
        .dark .cal-svc--urgent { background: rgba(159, 18, 57, 0.5); color: rgb(253 205 211); }

        .cal-tasks { margin-top: auto; display: flex; flex-direction: column; gap: 0.125rem; }
        .cal-task { display: flex; align-items: center; gap: 0.25rem; overflow: hidden; border-radius: 0.25rem;
            padding: 0.05rem 0.25rem; font-size: 0.6875rem;
            background: rgb(243 244 246); color: rgb(55 65 81); text-decoration: none; }
        .dark .cal-task { background: rgb(31 41 55); color: rgb(229 231 235); }
        .cal-task:hover { background: rgb(229 231 235); }
        .dark .cal-task:hover { background: rgb(55 65 81); }
        .cal-task--progress { background: rgb(254 243 199); color: rgb(146 64 14); }
        .dark .cal-task--progress { background: rgba(146, 64, 14, 0.45); color: rgb(253 230 138); }
        .cal-task__dot { flex-shrink: 0; width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: rgb(156 163 175); }
        .cal-task--progress .cal-task__dot { background: rgb(217 119 6); }
        .cal-task__label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cal-more { padding: 0 0.25rem; font-size: 0.625rem; color: rgb(156 163 175); }

        .cal-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 1rem; font-size: 0.75rem; color: rgb(107 114 128); }
        .dark .cal-legend { color: rgb(156 163 175); }
        .cal-legend span { display: inline-flex; align-items: center; gap: 0.375rem; }
        .cal-swatch { width: 0.75rem; height: 0.75rem; border-radius: 0.25rem; }
    </style>

    <div class="flex flex-col gap-4">
        {{-- Month heading + navigation --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $this->monthTitle }}</h2>

            <div class="flex items-center gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-right" wire:click="previousMonth">
                    קודם
                </x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="goToday">היום</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-left" icon-position="after" wire:click="nextMonth">
                    הבא
                </x-filament::button>
            </div>
        </div>

        {{-- Legend --}}
        <div class="cal-legend">
            <span><span class="cal-swatch" style="background: #c7d2fe;"></span> שבת / חג</span>
            <span><span class="cal-swatch" style="background: #fde68a;"></span> מתכונת מצומצמת</span>
            <span><span class="cal-swatch" style="background: #fecdd3;"></span> דחוף בלבד</span>
        </div>

        {{-- The month grid. Scrolls horizontally on narrow screens so the page body never does. --}}
        <div class="cal-wrap">
            <div class="cal-inner">
                <div class="cal-head">
                    @foreach (\App\Filament\Pages\Calendar::WEEKDAYS as $weekday)
                        <div>{{ $weekday }}</div>
                    @endforeach
                </div>

                <div class="cal-grid">
                    @foreach ($this->weeks as $week)
                        @foreach ($week as $day)
                            @php
                                /** @var \App\Models\ServiceException|null $service */
                                $service = $day['service'];
                                $rest = $day['rest'];
                                $tasks = $day['tasks'];
                                $cellClass = match (true) {
                                    $rest !== null => 'cal-cell--rest',
                                    $service?->mode === \App\Enums\ServiceMode::UrgentOnly => 'cal-cell--urgent',
                                    $service?->mode === \App\Enums\ServiceMode::Reduced => 'cal-cell--reduced',
                                    default => '',
                                };
                            @endphp

                            <div class="cal-cell {{ $cellClass }} {{ $day['inMonth'] ? '' : 'cal-cell--out' }}"
                                 aria-label="{{ $day['date']->format('d/m/Y') }} · {{ \App\Services\Calendar\HebrewDate::format($day['date']) }}">

                                {{-- Date line: Gregorian (prominent) + Hebrew numeral --}}
                                <div class="cal-cell__top">
                                    <span class="{{ $day['isToday'] ? 'cal-daynum--today' : 'cal-daynum' }}">{{ $day['gregorianDay'] }}</span>
                                    <span class="cal-hebday" aria-hidden="true">{{ $day['hebrewDay'] }}</span>
                                </div>

                                {{-- Hebrew month marker on Rosh Chodesh --}}
                                @if ($day['hebrewMonth'])
                                    <span class="cal-chip">{{ $day['hebrewMonth'] }}</span>
                                @endif

                                {{-- Shabbat / holiday --}}
                                @if ($rest)
                                    <div class="cal-rest">
                                        <span class="cal-rest__name">🕯️ {{ $rest['label'] }}</span>
                                        @if ($rest['last'])
                                            <span>צאת {{ $rest['exit']->format('H:i') }}</span>
                                        @endif
                                    </div>
                                @elseif ($day['candle'])
                                    <div class="cal-candle">🕯️ הדלקת נרות {{ $day['candle']->format('H:i') }}</div>
                                @endif

                                {{-- Special service day --}}
                                @if ($service)
                                    <span class="cal-svc {{ $service->mode === \App\Enums\ServiceMode::UrgentOnly ? 'cal-svc--urgent' : 'cal-svc--reduced' }}">
                                        {{ $service->mode === \App\Enums\ServiceMode::UrgentOnly ? 'דחוף בלבד' : 'מצומצמת' }}
                                    </span>
                                @endif

                                {{-- Tasks due this day --}}
                                @if ($tasks->isNotEmpty())
                                    <div class="cal-tasks">
                                        @foreach ($tasks->take(3) as $task)
                                            <a href="{{ \App\Filament\Resources\TaskResource::getUrl('edit', ['record' => $task->getKey()]) }}"
                                               wire:navigate
                                               class="cal-task {{ $task->status === \App\Enums\TaskStatus::InProgress ? 'cal-task--progress' : '' }}"
                                               title="{{ $task->title }}">
                                                <span class="cal-task__dot"></span>
                                                <span class="cal-task__label">{{ $task->title }}</span>
                                            </a>
                                        @endforeach

                                        @if ($tasks->count() > 3)
                                            <span class="cal-more">ועוד {{ $tasks->count() - 3 }}…</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
