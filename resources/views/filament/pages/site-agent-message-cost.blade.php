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

    {{-- Shown whenever the last pull failed — not only when there is no figure
         at all. A pull that keeps failing after one success leaves an ageing
         number on screen, and for the eight days the cache holds it the operator
         would have no way of knowing Meta has refused every request since. A
         stale figure presented as current is worse than none. --}}
    @if ($cost === null || $s['cost_error'])
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950">
            <p class="font-semibold text-warning-800 dark:text-warning-200">
                @if ($cost === null)
                    אין נתוני עלות ממטא.
                @else
                    הנתונים שלמטה אינם מעודכנים — הפנייה האחרונה למטא נכשלה
                    ({{ \Illuminate\Support\Carbon::parse($s['cost_error']['at'])->diffForHumans() }}).
                @endif
            </p>
            <p class="mt-1 text-warning-700 dark:text-warning-300">
                @if ($s['cost_error'])
                    {{ $s['cost_error']['reason'] }}
                @else
                    הנתונים עוד לא נשלפו. השליפה רצה פעם ביום, או בכפתור "רענון מול מטא" שלמעלה.
                @endif
            </p>
            {{-- Only where there is no figure at all. On a stale one we plainly
                 did reach Meta once, so "the WABA id is missing" is not the
                 explanation and offering it sends somebody to check a setting
                 that is already right. --}}
            @if ($cost === null)
                <p class="mt-2 text-xs text-warning-700 dark:text-warning-300">
                    הסיבות הנפוצות, ורק חלקן ניתנות לתיקון: חסר <strong>מזהה WABA</strong> במסך הבוט;
                    לא הצלחנו לזהות את מספר הטלפון של הבוט מול מטא (ובלעדיו העלות הייתה של כל המספרים
                    בחשבון, ולכן אינה מוצגת); או שהחשבון עובד דרך קו אשראי של שותף (Solution Partner) —
                    ואז מטא אינה מחזירה עלות בכלל, והמספר נמצא רק בחיוב של השותף.
                </p>
            @endif
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
                {{-- The charged count, not every message on the invoice: a plan
                     may bundle an allowance, and those messages cost us the same
                     while earning nothing. Dividing revenue by all of them would
                     report a price we never charged. --}}
                {{ number_format($s['billed_messages'] - $s['included_messages']) }} הודעות חויבו
                @if ($s['included_messages'] > 0)
                    · {{ number_format($s['included_messages']) }} כלולות במנוי
                @endif
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

        {{-- The break-even price, and deliberately NOT the blended cost of a
             message. Verification codes and system notices are charged to us and
             never charged on, so spreading the spend over them would report a
             fraction of the true figure under a label saying the plan must beat
             it. The denominator is the messages that actually carry a price. --}}
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">נקודת האיזון להודעה מחויבת</div>
            <div class="mt-1 text-2xl font-bold">
                {{ $s['break_even_agorot'] === null ? '—' : $ils($s['break_even_agorot']) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                זה המספר ש"מחיר להודעה" במסך המסלולים צריך לכסות — כל ההוצאה על ההודעות
                חלקי {{ number_format($s['charged_messages']) }} ההודעות שנושאות מחיר בתקופה
                (מחויבות, ואלה שטרם חויבו וייכנסו לחידוש הבא).
                @if ($s['pending_messages'] > 0)
                    {{-- Said rather than implied: how many of the pending ones will
                         land inside a plan's remaining allowance is unknowable
                         until that renewal computes it. --}}
                    <br>כמה מההודעות שטרם חויבו ייפלו בתוך מכסה כלולה אינו ידוע עד החידוש,
                    ולכן המספר עשוי להיות אופטימי במקצת.
                @endif
                @if ($perMessage !== null)
                    <br>העלות הממוצעת של הודעה כלשהי (כולל קודי אימות והודעות מערכת): {{ $ils($perMessage) }}.
                @endif
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
                @if ($s['unbilled_cost_agorot'] !== null)
                    {{-- The allocated share, not a rounded rate times a count:
                         multiplying a per-message figure that was rounded to a
                         whole agora overstates the total by up to 50% on small
                         averages, and reports zero on averages under half. --}}
                    העלות שלהן:
                    <strong>{{ $ils($s['unbilled_cost_agorot']) }}</strong> —
                    זה מחיר רכישת הלקוחות האלה, ולא תקלה.
                @endif
            </p>
            @if ($s['included_messages'] > 0)
                <p class="mt-2">
                    בנוסף <strong>{{ number_format($s['included_messages']) }}</strong> הודעות היו כלולות במנוי —
                    הן עלו לנו כמו כל השאר ולא נגבה עליהן בנפרד.
                    @if ($s['included_cost_agorot'] !== null)
                        העלות שלהן: <strong>{{ $ils($s['included_cost_agorot']) }}</strong>.
                    @endif
                </p>
            @endif
            @if ($s['pending_messages'] > 0)
                {{-- Owed but not yet earned. Messages are billed in arrears, so
                     the newest ones have no invoice yet — neither claimed as
                     revenue nor quietly lost. --}}
                <p class="mt-2">
                    ועוד <strong>{{ number_format($s['pending_messages']) }}</strong> הודעות נשלחו וטרם חויבו —
                    הן ייכנסו לחידוש הבא. העלות שלהן כבר נספרה למעלה, וההכנסה מהן עוד לא.
                </p>
            @endif
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                ההכנסה מיוחסת לפי <strong>מתי ההודעה נשלחה</strong> ולא לפי מתי יצאה החשבונית: הודעות
                מחויבות בדיעבד, וחידוש שיצא הבוקר יכול לכלול חודש שלם — חיתוך לפי תאריך החשבונית היה
                מעמיד שבוע של עלות מול חודש של הכנסה.
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
        {{-- אזהרת קריאה ולא טריוויה: מי שיקבע "מחיר להודעה" לפי חודש שכולו בתוך
             המכסה החינמית יתמחר לפי עלות שאינה קיימת בנפח גבוה. העלות עצמה נכונה
             תמיד, כי היא נשלפת ממטא ולא מחושבת מתעריף. --}}
        <br><strong>שימו לב למכסה החינמית:</strong> וואטסאפ מעמיד לכל מספר מכסה חודשית של הודעות
        שירות בחינם, ומעליה מתחיל החיוב. חודש שכולו בתוך המכסה יציג נקודת איזון נמוכה מאוד —
        אל תקבעו לפיו את "מחיר להודעה", כי בנפח גבוה יותר העלות אינה נשארת שם.
        {{-- שני המסכים עונים על שתי שאלות שונות, ומי שפתח אחד מהם כנראה רוצה גם
             את השני: כאן העלות של מטא על ההודעות, ושם עלות ה-AI לכל לקוח. --}}
        <br>לעלות ה-AI לכל לקוח בנפרד —
        <a href="{{ \App\Filament\Pages\SiteAgentUsageDashboard::getUrl() }}"
           class="text-primary-600 hover:underline dark:text-primary-400">שימוש בבוט ניהול האתר</a>.
    </p>
</x-filament-panels::page>
