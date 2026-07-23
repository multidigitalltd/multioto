<x-filament-panels::page>
    @php
        $rows = $this->rows;
        $totals = $this->totals;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
            הכנסה בפועל (חיובים שהצליחו) מול עומס הטיפול המשוער — פניות, הודעות נכנסות ותקלות אתר —
            מתורגם לעלות לפי תעריף שעת עבודה. לקוחות עם רווח נמוך או שלילי מוצגים ראשונים.
        </p>

        <div class="flex items-center gap-2">
            <label for="windowDays" class="text-sm font-medium">תקופה</label>
            <select id="windowDays" wire:model.live="windowDays"
                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                @foreach (\App\Filament\Pages\CustomerProfitability::WINDOWS as $days => $label)
                    <option value="{{ $days }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Summary strip. --}}
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">הכנסות בתקופה</div>
            <div class="mt-1 text-2xl font-bold">{{ \App\Support\Money::ils($totals['revenue']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">עלות טיפול משוערת</div>
            <div class="mt-1 text-2xl font-bold">{{ \App\Support\Money::ils($totals['cost']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">רווח משוער</div>
            <div @class([
                'mt-1 text-2xl font-bold',
                'text-danger-600 dark:text-danger-400' => $totals['profit'] < 0,
                'text-success-600 dark:text-success-400' => $totals['profit'] >= 0,
            ])>{{ \App\Support\Money::ils($totals['profit']) }}</div>
        </div>
    </div>

    <div class="rounded-xl bg-white shadow-sm dark:bg-gray-800">
        @if ($rows->isEmpty())
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">
                אין נתונים בתקופה שנבחרה — לא נרשמו חיובים, פניות או תקלות.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th scope="col" class="px-4 py-3 text-start font-medium">לקוח</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">הכנסה</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">פניות</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">הודעות</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">תקלות</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">זמן טיפול</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">עלות טיפול</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">רווח משוער</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">שולי רווח</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-700/60">
                                <td class="px-4 py-3 font-medium">
                                    <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $row['customer_id']]) }}"
                                       class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ \App\Support\Money::ils($row['revenue_agorot']) }}</td>
                                <td class="px-4 py-3">{{ $row['tickets'] }}</td>
                                <td class="px-4 py-3">{{ $row['messages'] }}</td>
                                <td class="px-4 py-3">{{ $row['incidents'] }}</td>
                                <td class="px-4 py-3">
                                    @php $h = intdiv($row['minutes'], 60); $m = $row['minutes'] % 60; @endphp
                                    {{ $h > 0 ? "{$h} ש׳ " : '' }}{{ $m > 0 || $h === 0 ? "{$m} דק׳" : '' }}
                                </td>
                                <td class="px-4 py-3">{{ \App\Support\Money::ils($row['cost_agorot']) }}</td>
                                <td @class([
                                    'px-4 py-3 font-semibold',
                                    'text-danger-600 dark:text-danger-400' => $row['profit_agorot'] < 0,
                                    'text-success-600 dark:text-success-400' => $row['profit_agorot'] >= 0,
                                ])>{{ \App\Support\Money::ils($row['profit_agorot']) }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['margin'] === null)
                                        <x-filament::badge color="gray">ללא הכנסה</x-filament::badge>
                                    @elseif ($row['margin'] < 0)
                                        <x-filament::badge color="danger">{{ $row['margin'] }}%</x-filament::badge>
                                    @elseif ($row['margin'] < 50)
                                        <x-filament::badge color="warning">{{ $row['margin'] }}%</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success">{{ $row['margin'] }}%</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        ההערכה מבוססת על משקלי זמן לתצורה: {{ (int) config('billing.profitability.minutes_per_ticket', 30) }} דק׳ לפנייה ·
        {{ (int) config('billing.profitability.minutes_per_message', 4) }} דק׳ להודעה נכנסת ·
        {{ (int) config('billing.profitability.minutes_per_incident', 20) }} דק׳ לתקלה ·
        תעריף {{ \App\Support\Money::ils((int) config('billing.profitability.hourly_cost_agorot', 15000)) }} לשעה.
    </p>
</x-filament-panels::page>
