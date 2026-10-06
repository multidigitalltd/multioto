<x-filament-panels::page>
    @php
        $rows = $this->rows;
        $totals = $this->totals;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
            לכל לקוח של הבוט: הודעות ויחידות כתיבה בתקופה, ההכנסה שנגבתה בפועל על מנויי הבוט, ועלות ה-AI שהוצאה עליו
            (הערכה לפי מחירי הטוקנים ושער של ₪{{ number_format((float) config('billing.ai.usd_ils_rate', 3.7), 2) }} לדולר).
            לקוחות עם הפער הנמוך ביותר מוצגים ראשונים.
        </p>

        <div class="flex items-center gap-2">
            <label for="windowDays" class="text-sm font-medium">תקופה</label>
            <select id="windowDays" wire:model.live="windowDays"
                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                @foreach (\App\Filament\Pages\SiteAgentUsageDashboard::WINDOWS as $days => $label)
                    <option value="{{ $days }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">לקוחות פעילים</div>
            <div class="mt-1 text-2xl font-bold">{{ number_format($totals['customers']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">הודעות</div>
            <div class="mt-1 text-2xl font-bold">{{ number_format($totals['messages']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">יחידות כתיבה</div>
            <div class="mt-1 text-2xl font-bold">{{ number_format($totals['writings']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">הכנסה מהבוט</div>
            <div class="mt-1 text-2xl font-bold">{{ \App\Support\Money::ils($totals['revenue']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">עלות AI משוערת</div>
            <div class="mt-1 text-2xl font-bold">{{ \App\Support\Money::ils($totals['cost']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">פער</div>
            <div @class([
                'mt-1 text-2xl font-bold',
                'text-danger-600 dark:text-danger-400' => $totals['margin'] < 0,
                'text-success-600 dark:text-success-400' => $totals['margin'] >= 0,
            ])>{{ \App\Support\Money::ils($totals['margin']) }}</div>
        </div>
    </div>

    <div class="rounded-xl bg-white shadow-sm dark:bg-gray-800">
        @if ($rows->isEmpty())
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">אין עדיין לקוחות עם מנוי פעיל לבוט ניהול האתר.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <caption class="sr-only">שימוש, הכנסה ועלות AI לכל לקוח של בוט ניהול האתר</caption>
                    <thead>
                        <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th scope="col" class="px-4 py-3 text-start font-medium">לקוח</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">מספרים</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">הודעות</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">יחידות כתיבה</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">הכנסה</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">עלות AI</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">פער</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">תקרה</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-700/60">
                                <td class="px-4 py-3 font-medium">
                                    <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $row['customer_id']]) }}"
                                       class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['name'] }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $row['numbers'] }}</td>
                                <td class="px-4 py-3">{{ number_format($row['messages']) }}</td>
                                <td class="px-4 py-3">{{ number_format($row['writings']) }}</td>
                                <td class="px-4 py-3">{{ \App\Support\Money::ils($row['revenue_agorot']) }}</td>
                                <td class="px-4 py-3">{{ \App\Support\Money::ils($row['ai_cost_agorot']) }}</td>
                                <td @class([
                                    'px-4 py-3 font-medium',
                                    'text-danger-600 dark:text-danger-400' => $row['margin_agorot'] < 0,
                                ])>{{ \App\Support\Money::ils($row['margin_agorot']) }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['cap'] !== null)
                                        @php $share = $row['cap'] > 0 ? (int) floor($row['cap_used'] * 100 / $row['cap']) : 0; @endphp
                                        <span @class(['font-medium text-warning-600 dark:text-warning-400' => $share >= 80])>
                                            {{ number_format($row['cap_used']) }} / {{ number_format($row['cap']) }} ({{ $share }}%)
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
