<x-filament-panels::page>
    @php
        /*
         | A local helper rather than a `use` statement: Blade compiles @php into
         | an inline block, where a use statement is a parse error.
         */
        $ils = fn (int $agorot): string => \App\Support\Money::ils($agorot);

        $s = $this->summary;
        $cost = $s['cost'];
        $perMessage = $this->costPerMessage;

        /*
         | Category names in the operator's language. Meta's own keys, which are
         | what arrive — an unknown key is shown as it came rather than mapped to
         | something plausible, because a category we do not recognise is exactly
         | the one worth looking at.
         */
        $labels = [
            'service' => 'שיחה (תשובות הבוט)',
            'utility' => 'Utility (הודעות שירות)',
            'authentication' => 'אימות (קודים)',
            'marketing' => 'שיווק',
            'referral_conversion' => 'הפניה',
            'unknown' => 'לא מסווג',
        ];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
            מה שמטא גובה מאיתנו על ההודעות של הבוט, מול מה שגבינו מהלקוחות עליהן.
            העלות היא הנתון של מטא עצמה ולא תחשיב לפי תעריף, ונשלפת פעם ביום.
            <strong>מאז 1 באוקטובר 2026 גם תשובה רגילה בשיחה פתוחה עולה כסף</strong> —
            עד אז היא הייתה חינם, ולכן מרווח שהתהפך ביום ההוא לא היה נראה באף מסך.
        </p>

        <div class="flex items-center gap-2">
            <label for="windowDays" class="text-sm font-medium">תקופה</label>
            <select id="windowDays" wire:model.live="windowDays"
                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                @foreach (\App\Filament\Pages\SiteAgentMessageCost::WINDOWS as $days => $label)
                    <option value="{{ $days }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- The cost could not be read. Said plainly, and never as ₪0: that is the
         one figure that would be taken at face value. --}}
    @if ($cost === null)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950">
            <p class="font-semibold text-warning-800 dark:text-warning-200">אין נתוני עלות ממטא.</p>
            <p class="mt-1 text-warning-700 dark:text-warning-300">
                @if ($s['cost_error'])
                    {{ $s['cost_error']['reason'] }}
                @else
                    הנתונים עוד לא נשלפו. השליפה רצה פעם ביום, או בכפתור "רענון מול מטא" שלמעלה.
                @endif
            </p>
            <p class="mt-2 text-xs text-warning-700 dark:text-warning-300">
                שתי סיבות נפוצות, ורק אחת מהן ניתנת לתיקון: חסר <strong>מזהה WABA</strong> במסך הבוט,
                או שהחשבון עובד דרך קו אשראי של שותף (Solution Partner) — ואז מטא אינה מחזירה עלות בכלל,
                והמספר נמצא רק בחיוב של השותף.
            </p>
        </div>
    @endif

    {{-- Summary strip. --}}
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));">
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">עלות ממטא</div>
            <div class="mt-1 text-2xl font-bold">
                @if ($cost === null)
                    <span class="text-gray-400">לא ידוע</span>
                @elseif ($s['comparable'])
                    {{ $ils((int) $cost['total']) }}
                @else
                    {{ number_format(((int) $cost['total']) / 100, 2) }} {{ $cost['currency'] ?: '?' }}
                @endif
            </div>
            @if ($cost !== null)
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ number_format((int) $cost['messages']) }} הודעות · נשלף
                    {{ \Illuminate\Support\Carbon::parse($cost['pulled_at'])->diffForHumans() }}
                </div>
            @endif
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">חויב מהלקוחות (ללא מע״מ)</div>
            <div class="mt-1 text-2xl font-bold">{{ $ils($s['revenue_net']) }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($s['billed_messages']) }} הודעות בחשבוניות
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">מרווח</div>
            <div @class([
                'mt-1 text-2xl font-bold',
                'text-gray-400' => $s['margin'] === null,
                'text-danger-600 dark:text-danger-400' => $s['margin'] !== null && $s['margin'] < 0,
                'text-success-600 dark:text-success-400' => $s['margin'] !== null && $s['margin'] >= 0,
            ])>
                {{ $s['margin'] === null ? 'לא ניתן לחשב' : $ils($s['margin']) }}
            </div>
            @if ($s['margin'] === null && $cost !== null && ! $s['comparable'])
                {{-- Deliberately not computed. Subtracting shekels from dollars
                     needs an exchange rate, and a rate we picked ourselves would
                     be the one assumption invisible on the screen. --}}
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    מטא מדווחת ב-{{ $cost['currency'] ?: 'מטבע אחר' }} ולא בשקלים, ולכן לא חיסרנו —
                    שער שנבחר כאן היה הופך הפסד לרווח בלי שאף אחד יראה זאת.
                </div>
            @endif
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">עלות ממוצעת להודעה</div>
            <div class="mt-1 text-2xl font-bold">
                {{ $perMessage === null ? '—' : $ils($perMessage) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                זה המספר ש"מחיר להודעה" במסך המסלולים צריך לכסות.
            </div>
        </div>
    </div>

    {{-- The gap that flatters a margin: messages nobody pays for cost exactly as
         much as the ones that are billed. --}}
    @if ($s['sent_messages'] > 0)
        <div class="rounded-xl bg-white p-4 text-sm shadow-sm dark:bg-gray-800">
            <p>
                הבוט שלח <strong>{{ number_format($s['sent_messages']) }}</strong> תשובות בתקופה,
                מהן <strong>{{ number_format($s['unbilled_messages']) }}</strong> שלא יחויבו לאף לקוח
                (תקופת ניסיון, או מסלול שאינו מתמחר הודעות).
                @if ($s['unbilled_messages'] > 0 && $perMessage !== null)
                    העלות שלהן:
                    <strong>{{ $ils($s['unbilled_messages'] * $perMessage) }}</strong> —
                    זה מחיר רכישת הלקוחות האלה, ולא תקלה.
                @endif
            </p>
            @if ($s['estimated_rows'] > 0)
                <p class="mt-2 text-xs text-warning-700 dark:text-warning-300">
                    {{ $s['estimated_rows'] }} חיובים נוצרו לפני שסכום ההודעות ללא מע״מ נשמר על השורה,
                    ולכן אינם נכללים בהכנסה שלמעלה. הם ייכללו מהחידוש הבא שלהם.
                </p>
            @endif
        </div>
    @endif

    {{-- Per category. This is where "service is not free any more" becomes
         visible as a line of its own. --}}
    @if ($cost !== null && $cost['by_category'] !== [])
        <div class="rounded-xl bg-white shadow-sm dark:bg-gray-800">
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th scope="col" class="px-4 py-3 text-start font-medium">קטגוריה</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">הודעות</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">עלות</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">להודעה</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cost['by_category'] as $key => $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-700/60">
                                <td class="px-4 py-3 font-medium">{{ $labels[$key] ?? $key }}</td>
                                <td class="px-4 py-3">{{ number_format((int) $row['messages']) }}</td>
                                <td class="px-4 py-3">
                                    @if ($s['comparable'])
                                        {{ $ils((int) $row['cost']) }}
                                    @else
                                        {{ number_format(((int) $row['cost']) / 100, 2) }} {{ $cost['currency'] }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                    @if ((int) $row['messages'] > 0 && $s['comparable'])
                                        {{ $ils((int) round(((int) $row['cost']) / ((int) $row['messages']))) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">
        מטא מתמחרת לפי ארץ הנמען ולפי קטגוריה, עם מדרגות נפח — ולכן המספר כאן הוא מה שנגבה בפועל
        ולא תעריף שמישהו הזין. ההכנסה נלקחת מהחיובים שהצליחו, לפני מע״מ, כי המע״מ אינו שלנו.
    </p>
</x-filament-panels::page>
