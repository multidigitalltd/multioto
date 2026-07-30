<x-filament-panels::page>
    @php
        $rows = $this->rows;
        $counts = $this->counts;
        $money = fn (int $agorot): string => '₪'.number_format($agorot / 100, 0);
    @endphp

    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    שווי מוערך של ההזדמנויות שמוצגות כאן
                </p>
                <p class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $money($this->totalAgorot) }}</p>
            </div>
            <p class="max-w-xl text-xs text-gray-500 dark:text-gray-400">
                המחירים הם נקודת פתיחה להצעה (ניתנים לשינוי בהגדרות המערכת) ומוצגים לצוות בלבד — שום דבר לא נשלח ללקוח אוטומטית.
            </p>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            @foreach (\App\Filament\Pages\OpportunityRadarPage::FILTERS as $value => $label)
                <button type="button" wire:click="$set('filter', '{{ $value }}')"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            'bg-primary-600 text-white' => $this->filter === $value,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200' => $this->filter !== $value,
                        ])>
                    {{ $label }}
                    <span class="text-xs opacity-75">({{ $counts[$value] ?? 0 }})</span>
                </button>
            @endforeach

            <label class="ms-auto flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" wire:model.live="urgentOnly"
                       class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-700">
                רק דחוף
            </label>

            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="חיפוש לפי דומיין או לקוח"
                   aria-label="חיפוש לפי דומיין או לקוח"
                   class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        </div>
    </div>

    @forelse ($rows as $row)
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <a href="{{ route('filament.admin.resources.sites.view', ['record' => $row['site_id']]) }}"
                       class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                        {{ $row['domain'] }}
                    </a>
                    @if ($row['customer'])
                        <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row['customer'] }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm font-bold text-success-600 dark:text-success-400">{{ $money($row['total_agorot']) }}</span>
                    @if ($row['scanned_at'])
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            נסרק: {{ \Illuminate\Support\Carbon::parse($row['scanned_at'])->format('d/m/Y') }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="grid gap-2">
                @foreach ($row['items'] as $item)
                    @php $arguments = ['site' => $row['site_id'], 'key' => $item['key'] ?? '']; @endphp

                    <div class="border-b border-gray-100 pb-2 last:border-0 dark:border-gray-700">
                        <div class="flex flex-wrap items-start gap-x-3 gap-y-1">
                            <x-filament::badge :color="($item['severity'] ?? 'medium') === 'high' ? 'danger' : 'warning'">
                                {{ ($item['severity'] ?? 'medium') === 'high' ? 'דחוף' : 'מומלץ' }}
                            </x-filament::badge>
                            <span class="text-sm font-medium">{{ $item['title'] ?? '' }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $money((int) ($item['price_agorot'] ?? 0)) }}</span>
                            <p class="w-full text-xs text-gray-500 dark:text-gray-400">{{ $item['evidence'] ?? '' }}</p>

                            {{-- Who decided matters even without a reason, and an
                                 "offered" verdict never carries one at all. --}}
                            @if (($item['reason'] ?? null) || ($item['decided_by'] ?? null))
                                <p class="w-full text-xs text-gray-400 dark:text-gray-500">
                                    @if ($item['reason'] ?? null)
                                        סיבה: {{ $item['reason'] }}@if ($item['decided_by'] ?? null) · @endif
                                    @endif
                                    @if ($item['decided_by'] ?? null)
                                        סומן על ידי {{ $item['decided_by'] }}
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($this->filter === 'open')
                                {{ ($this->openTaskAction)($arguments) }}
                                {{ ($this->markOfferedAction)($arguments) }}
                                {{ ($this->dismissAction)($arguments) }}
                            @else
                                {{ ($this->restoreAction)($arguments) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($this->filter === 'open')
                    אין כרגע הזדמנויות פתוחות. הסריקה רצה אוטומטית בימי ראשון — או לחצו "סרוק את כל האתרים עכשיו" למעלה.
                @elseif ($this->filter === 'dismissed')
                    לא נדחו הזדמנויות.
                @else
                    לא סומנו הזדמנויות כהוצעו ללקוח.
                @endif
            </p>
        </div>
    @endforelse

    <x-filament-actions::modals />
</x-filament-panels::page>
