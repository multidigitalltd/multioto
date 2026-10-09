<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check">
                שמירת הגדרות הסוכן
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-signal"
                                wire:click="testConnection" wire:loading.attr="disabled">
                בדיקת חיבור לספק
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-academic-cap"
                                wire:click="learnFromHistory" wire:loading.attr="disabled">
                למד מתשובות קודמות
            </x-filament::button>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            שמרו את ההגדרות ואז לחצו "בדיקת חיבור" כדי לוודא שהמפתח, הספק והדגם עובדים. אם נכשל — פרטי השגיאה נכתבים ליומן המערכת.
        </p>
    </form>

    @php $cost = $this->costSnapshot; @endphp
    <x-filament::section class="mt-6" icon="heroicon-o-banknotes">
        <x-slot name="heading">שימוש בטוקנים ועלות משוערת</x-slot>
        <x-slot name="description">
            אומדן לפי המחיר הרגיל שבהגדרות, ללא הנחות מטמון וללא דמי אחסון מטמון. זה אינו החיוב בפועל או תקרת עלות; החשבונית של הספק היא המקור המחייב. מתעדכן אחת ל-24 שעות (או ידנית).
        </x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">אומדן מצטבר</div>
                <div class="mt-1 text-2xl font-bold">${{ number_format($cost['total']['usd'], 2) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($cost['total']['requests']) }} קריאות · {{ number_format($cost['total']['input_tokens'] + $cost['total']['output_tokens']) }} טוקנים</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    קלט מהמטמון: {{ number_format($cost['total']['cached_input_tokens'] ?? 0) }}
                    ({{ number_format($cost['total']['input_tokens'] > 0 ? 100 * ($cost['total']['cached_input_tokens'] ?? 0) / $cost['total']['input_tokens'] : 0, 1) }}% מהקלט)
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">אומדן החודש</div>
                <div class="mt-1 text-2xl font-bold">${{ number_format($cost['this_month']['usd'], 2) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($cost['this_month']['requests']) }} קריאות</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    קלט מהמטמון: {{ number_format($cost['this_month']['cached_input_tokens'] ?? 0) }}
                    ({{ number_format($cost['this_month']['input_tokens'] > 0 ? 100 * ($cost['this_month']['cached_input_tokens'] ?? 0) / $cost['this_month']['input_tokens'] : 0, 1) }}% מהקלט)
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">עודכן</div>
                <div class="mt-1 text-sm font-medium">{{ \Illuminate\Support\Carbon::parse($cost['as_of'])->format('d/m/Y H:i') }}</div>
                <x-filament::button type="button" size="xs" color="gray" icon="heroicon-o-arrow-path"
                                    wire:click="refreshCost" wire:loading.attr="disabled" class="mt-2">
                    רענן עכשיו
                </x-filament::button>
            </div>
        </div>

        @if (! empty($cost['by_model']))
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th scope="col" class="py-2 text-start font-medium">דגם</th>
                            <th scope="col" class="py-2 text-start font-medium">קריאות</th>
                            <th scope="col" class="py-2 text-start font-medium">טוקנים (קלט/פלט)</th>
                            <th scope="col" class="py-2 text-start font-medium">מתוך הקלט: מטמון</th>
                            <th scope="col" class="py-2 text-start font-medium">עלות משוערת</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cost['by_model'] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 font-mono text-xs">{{ $row['model'] ?: 'לא ידוע' }}</td>
                                <td class="py-2">{{ number_format($row['requests']) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ number_format($row['input_tokens']) }} / {{ number_format($row['output_tokens']) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">
                                    {{ number_format($row['cached_input_tokens'] ?? 0) }}
                                    ({{ number_format($row['input_tokens'] > 0 ? 100 * ($row['cached_input_tokens'] ?? 0) / $row['input_tokens'] : 0, 1) }}%)
                                </td>
                                <td class="py-2 font-semibold">${{ number_format($row['usd'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">פירוט לפי דגם — שלושת החודשים האחרונים.</p>
            </div>
        @else
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">עדיין לא נרשם שימוש. ברגע שהסוכן יתחיל לעבוד, העלות תופיע כאן.</p>
        @endif
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            טוקני המטמון הם חלק מטוקני הקלט. המספרים והאחוזים מבוססים על דיווחי הספק שנרשמו מהעדכון הנוכחי, ואינם מציינים חיסכון כספי; שימוש קודם אינו כולל פירוט מטמון.
        </p>
    </x-filament::section>

    <x-filament::section class="mt-6" icon="heroicon-o-shield-check">
        <x-slot name="heading">בטיחות</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            כל תשובה שהסוכן מנסח נשמרת כ<strong>טיוטה פנימית</strong> וממתינה לאישור אנושי לפני שליחה —
            הסוכן לעולם לא פונה ללקוח בעצמו. פעולות קריאה (סטטוס אתר, מידע חיוב) רצות אוטומטית;
            פעולות שינוי (קישור לעדכון כרטיס) מתווספות לטיוטה ומגיעות ללקוח רק אם תאשרו.
            המפתח נשמר מוצפן ואינו מוצג חזרה.
        </p>
    </x-filament::section>
</x-filament-panels::page>
