<x-filament-panels::page>
    @php
        $rows = $this->rows;
        $total = $this->totalAgorot;
        $money = fn (int $agorot): string => '₪'.number_format($agorot / 100, 0);
    @endphp

    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">שווי מוערך של כל ההזדמנויות</p>
                <p class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $money($total) }}</p>
            </div>
            <p class="max-w-xl text-xs text-gray-500 dark:text-gray-400">
                המחירים הם נקודת פתיחה להצעה (ניתנים לשינוי בהגדרות המערכת) ומוצגים לצוות בלבד — שום דבר לא נשלח ללקוח אוטומטית.
            </p>
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
                    <div class="flex flex-wrap items-start gap-x-3 gap-y-1 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-700">
                        <x-filament::badge :color="($item['severity'] ?? 'medium') === 'high' ? 'danger' : 'warning'">
                            {{ ($item['severity'] ?? 'medium') === 'high' ? 'דחוף' : 'מומלץ' }}
                        </x-filament::badge>
                        <span class="text-sm font-medium">{{ $item['title'] ?? '' }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $money((int) ($item['price_agorot'] ?? 0)) }}</span>
                        <p class="w-full text-xs text-gray-500 dark:text-gray-400">{{ $item['evidence'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                עדיין אין הזדמנויות שזוהו. הסריקה רצה אוטומטית בימי ראשון — או לחצו "סרוק את כל האתרים עכשיו" למעלה.
            </p>
        </div>
    @endforelse
</x-filament-panels::page>
