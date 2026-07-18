<x-filament-panels::page>
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
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5">
                <span class="size-3 rounded bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-950 dark:ring-indigo-800"></span>
                שבת / חג
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-3 rounded bg-amber-100 ring-1 ring-amber-300 dark:bg-amber-950 dark:ring-amber-800"></span>
                מתכונת מצומצמת
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-3 rounded bg-rose-100 ring-1 ring-rose-300 dark:bg-rose-950 dark:ring-rose-800"></span>
                דחוף בלבד
            </span>
        </div>

        {{-- The month grid. Scrolls horizontally on narrow screens so the page body never does. --}}
        <div class="overflow-x-auto">
            <div class="min-w-[46rem] overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                {{-- Weekday header --}}
                <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                    @foreach (\App\Filament\Pages\Calendar::WEEKDAYS as $weekday)
                        <div class="px-2 py-1.5 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">
                            {{ $weekday }}
                        </div>
                    @endforeach
                </div>

                {{-- Day cells --}}
                <div class="grid grid-cols-7">
                    @foreach ($this->weeks as $week)
                        @foreach ($week as $day)
                            @php
                                /** @var \App\Models\ServiceException|null $service */
                                $service = $day['service'];
                                $rest = $day['rest'];
                                $tone = match (true) {
                                    $rest !== null => 'bg-indigo-50 dark:bg-indigo-950/30',
                                    $service?->mode === \App\Enums\ServiceMode::UrgentOnly => 'bg-rose-50 dark:bg-rose-950/30',
                                    $service?->mode === \App\Enums\ServiceMode::Reduced => 'bg-amber-50 dark:bg-amber-950/30',
                                    ! $day['inMonth'] => 'bg-gray-50 dark:bg-gray-950/40',
                                    default => 'bg-white dark:bg-gray-900',
                                };
                                $tasks = $day['tasks'];
                            @endphp

                            <div @class([
                                    'relative flex min-h-[7.5rem] flex-col gap-1 border-b border-s border-gray-100 p-2 dark:border-gray-800',
                                    $tone,
                                    'opacity-60' => ! $day['inMonth'],
                                ])
                                @if ($day['isToday']) style="box-shadow: inset 0 0 0 2px rgb(var(--primary-500));" @endif
                                aria-label="{{ $day['date']->format('d/m/Y') }} · {{ \App\Services\Calendar\HebrewDate::format($day['date']) }}">

                                {{-- Date line: Gregorian (prominent) + Hebrew numeral --}}
                                <div class="flex items-start justify-between">
                                    <span @class([
                                        'flex size-6 items-center justify-center rounded-full text-sm font-semibold',
                                        'bg-primary-600 text-white' => $day['isToday'],
                                        'text-gray-700 dark:text-gray-200' => ! $day['isToday'],
                                    ])>{{ $day['gregorianDay'] }}</span>

                                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500" aria-hidden="true">{{ $day['hebrewDay'] }}</span>
                                </div>

                                {{-- Hebrew month marker on Rosh Chodesh --}}
                                @if ($day['hebrewMonth'])
                                    <span class="w-fit rounded bg-gray-100 px-1 text-[10px] font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                        {{ $day['hebrewMonth'] }}
                                    </span>
                                @endif

                                {{-- Shabbat / holiday --}}
                                @if ($rest)
                                    <div class="flex flex-col text-[11px] leading-tight text-indigo-700 dark:text-indigo-300">
                                        <span class="font-semibold">🕯️ {{ $rest['label'] }}</span>
                                        @if ($rest['last'])
                                            <span>צאת {{ $rest['exit']->format('H:i') }}</span>
                                        @endif
                                    </div>
                                @elseif ($day['candle'])
                                    <div class="text-[11px] leading-tight text-indigo-600/80 dark:text-indigo-300/80">
                                        🕯️ הדלקת נרות {{ $day['candle']->format('H:i') }}
                                    </div>
                                @endif

                                {{-- Special service day --}}
                                @if ($service)
                                    <span @class([
                                        'w-fit rounded px-1.5 py-0.5 text-[10px] font-semibold',
                                        'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300' => $service->mode === \App\Enums\ServiceMode::UrgentOnly,
                                        'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300' => $service->mode === \App\Enums\ServiceMode::Reduced,
                                    ])>
                                        {{ $service->mode === \App\Enums\ServiceMode::UrgentOnly ? 'דחוף בלבד' : 'מצומצמת' }}
                                    </span>
                                @endif

                                {{-- Tasks due this day --}}
                                @if ($tasks->isNotEmpty())
                                    <div class="mt-auto flex flex-col gap-0.5">
                                        @foreach ($tasks->take(3) as $task)
                                            <a href="{{ \App\Filament\Resources\TaskResource::getUrl('edit', ['record' => $task->getKey()]) }}"
                                               wire:navigate
                                               @class([
                                                   'group flex items-center gap-1 truncate rounded px-1 py-0.5 text-[11px]',
                                                   'bg-warning-100 text-warning-800 hover:bg-warning-200 dark:bg-warning-900/40 dark:text-warning-200' => $task->status === \App\Enums\TaskStatus::InProgress,
                                                   'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200' => $task->status !== \App\Enums\TaskStatus::InProgress,
                                               ])
                                               title="{{ $task->title }}">
                                                <span @class([
                                                    'size-1.5 shrink-0 rounded-full',
                                                    'bg-warning-500' => $task->status === \App\Enums\TaskStatus::InProgress,
                                                    'bg-gray-400' => $task->status !== \App\Enums\TaskStatus::InProgress,
                                                ])></span>
                                                <span class="truncate">{{ $task->title }}</span>
                                            </a>
                                        @endforeach

                                        @if ($tasks->count() > 3)
                                            <span class="px-1 text-[10px] text-gray-400">ועוד {{ $tasks->count() - 3 }}…</span>
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
