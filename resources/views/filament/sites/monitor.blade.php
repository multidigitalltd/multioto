<x-filament-panels::page>
    @php
        $site = $this->record;
        $stats = $this->stats;
        $warnDays = (int) config('billing.monitoring.ssl_warn_days', 14);
        $slowMs = (int) config('billing.monitoring.slow_response_ms', 4000);
        $ssl = $site->ssl_days_left;
        $isDown = (bool) $site->openIncident;
    @endphp

    {{-- Context strip: site + customer + live state. --}}
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="font-semibold">{{ $site->domain }}</span>
        <x-filament::badge :color="$isDown ? 'danger' : 'success'">
            {{ $isDown ? 'לא זמין' : 'זמין' }}
        </x-filament::badge>
        @if ($site->customer)
            <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $site->customer]) }}"
               class="text-primary-600 hover:underline">{{ $site->customer->name }} ←</a>
        @endif
    </div>

    {{-- Stat cards: uptime, response time, SSL — the whole health picture. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" wire:poll.30s>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">זמינות ({{ $this->getStatsWindowDays() }} ימים)</div>
            <div class="mt-1 text-2xl font-bold">
                {{ $stats['uptime'] !== null ? $stats['uptime'] . '%' : '—' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $stats['up'] }} / {{ $stats['total'] }} בדיקות תקינות
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">זמן תגובה ממוצע</div>
            <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-amber-600 dark:text-amber-400' => $stats['avg_ms'] !== null && $stats['avg_ms'] >= $slowMs,
                ])>
                {{ $stats['avg_ms'] !== null ? number_format($stats['avg_ms']) . ' ms' : '—' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($stats['avg_ms'] !== null && $stats['avg_ms'] >= $slowMs) איטי מהרגיל @else תקין @endif
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">תעודת SSL</div>
            <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $ssl !== null && $ssl <= 0,
                    'text-amber-600 dark:text-amber-400' => $ssl !== null && $ssl > 0 && $ssl <= $warnDays,
                ])>
                @if ($ssl === null) — @elseif ($ssl <= 0) פגה @else {{ $ssl }} ימים @endif
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($ssl !== null && $ssl > 0 && $ssl <= $warnDays) עומדת לפוג — מומלץ לחדש @else נותרו עד לחידוש @endif
            </div>
        </div>
    </div>

    {{-- Recent probes. Polls so a live outage/recovery updates in place. --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:poll.30s>
        <h3 class="mb-3 text-sm font-semibold">בדיקות אחרונות</h3>
        <div style="overflow-x: auto;">
            <table class="w-full text-sm">
                <caption class="sr-only">היסטוריית בדיקות ניטור אחרונות עבור {{ $site->domain }}</caption>
                <thead>
                    <tr class="text-xs text-gray-500 dark:text-gray-400">
                        <th scope="col" class="p-2 text-start font-medium">מתי</th>
                        <th scope="col" class="p-2 text-start font-medium">מצב</th>
                        <th scope="col" class="p-2 text-start font-medium">קוד HTTP</th>
                        <th scope="col" class="p-2 text-start font-medium">זמן תגובה</th>
                        <th scope="col" class="p-2 text-start font-medium">שגיאה</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->recentChecks as $check)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="p-2 whitespace-nowrap">
                                <time datetime="{{ $check->checked_at->toIso8601String() }}">{{ $check->checked_at->format('d/m/Y H:i') }}</time>
                            </td>
                            <td class="p-2">
                                <x-filament::badge :color="$check->is_up ? 'success' : 'danger'">
                                    {{ $check->is_up ? 'תקין' : 'נפילה' }}
                                </x-filament::badge>
                            </td>
                            <td class="p-2">{{ $check->status_code ?? '—' }}</td>
                            <td @class([
                                    'p-2 whitespace-nowrap',
                                    'text-amber-600 dark:text-amber-400' => $check->response_ms >= $slowMs,
                                ])>{{ number_format($check->response_ms) }} ms</td>
                            <td class="p-2 text-gray-500 dark:text-gray-400">{{ $check->error ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-4 text-center text-gray-500">אין בדיקות ניטור עדיין.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
