<x-filament-panels::page>
    @php
        $hours = ['0' => '00', '1' => '01', '2' => '02', '3' => '03', '4' => '04', '5' => '05',
                  '6' => '06', '7' => '07', '8' => '08', '9' => '09'];
        $weekdayNames = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
        $pad = fn (int $h): string => str_pad((string) $h, 2, '0', STR_PAD_LEFT).':00';
    @endphp

    {{-- אין מדידה בכלל. אפסים היו נראים כמו "אף אחד לא פותח" ומובילים בדיוק
         להחלטה ההפוכה מהנכונה. --}}
    @unless ($hasData)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
             role="status">
            <p class="font-semibold text-amber-900 dark:text-amber-200">אין עדיין נתוני פתיחה</p>
            <p class="mt-1 text-amber-900 dark:text-amber-200">
                @if ($rejectedAt)
                    ספק המייל פונה אלינו אך נדחה (אחרון: {{ $rejectedAt->format('d/m/Y H:i') }}) — הכתובת שהודבקה ב-Postmark
                    אינה כוללת את הסוד הנכון. תקנו אותה בהגדרות ← מייל ושולח, והנתונים יתחילו להצטבר.
                @else
                    כדי לדעת מי פותח, Postmark צריך לדווח לנו. בהגדרות ← מייל ושולח נמצאת הכתובת להדבקה, וב-Postmark
                    יש לסמן Delivery, Bounce, Spam Complaint ו-Open, ולוודא ש-Open Tracking מופעל.
                @endif
            </p>
            <p class="mt-2 text-xs text-amber-800 dark:text-amber-300">
                עד אז אין דילוג על אף לקוח: היעדר מדידה אינו ראיה לכך שהוא אינו קורא.
            </p>
        </div>
    @endunless

    {{-- איפה בדיוק נשברה השרשרת. "אין פתיחות" הוא אותו מסך בשביל חמש תקלות
         שונות, ולכל אחת מהן תיקון אחר — כאן כתוב איזו מהן זו.

         הכפתור "Check" של Postmark אינו מבחין ביניהן: הוא שולח אירוע לדוגמה
         ומסתפק בכל תשובת 200. גם אנחנו מחזירים 200 לאירוע שלא התאים לשום
         הודעה, כי סירוב היה גורם לספק לנסות שוב אירוע שאין מה לעשות איתו. --}}
    @if ($diagnosis)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900">
            <p class="font-semibold text-gray-900 dark:text-gray-100">אבחון: איפה זה נעצר</p>

            <p class="mt-2 text-gray-800 dark:text-gray-200">{{ $diagnosis['verdict'] }}</p>
            <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $diagnosis['fix'] }}</p>

            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-gray-600 dark:text-gray-400 sm:grid-cols-3">
                <div>
                    <dt class="inline">אירועים שהתקבלו מ-Postmark:</dt>
                    <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $diagnosis['total'] }}</dd>
                </div>
                <div>
                    <dt class="inline">מתוכם פתיחות:</dt>
                    <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $diagnosis['events']['Open'] ?? 0 }}</dd>
                </div>
                <div>
                    <dt class="inline">פתיחות ששויכו להודעה שלנו:</dt>
                    <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $diagnosis['openMatched'] }}</dd>
                </div>
                <div>
                    <dt class="inline">הודעות דיוור שנשלחו:</dt>
                    <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $diagnosis['sent'] }}</dd>
                </div>
                <div>
                    <dt class="inline">מתוכן ניתנות לשיוך:</dt>
                    <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $diagnosis['tracked'] }}</dd>
                </div>
                <div>
                    <dt class="inline">אירוע אחרון:</dt>
                    <dd class="inline font-medium text-gray-900 dark:text-gray-100">
                        {{ $diagnosis['lastEventAt']?->format('d/m/Y H:i') ?? 'מעולם' }}
                    </dd>
                </div>
            </dl>

            @if ($diagnosis['events'] !== [])
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                    לפי סוג ({{ $diagnosis['since']->format('d/m/Y') }} ואילך):
                    @foreach ($diagnosis['events'] as $type => $count){{ $type }} — {{ $count }}@if (! $loop->last) · @endif @endforeach
                </p>
            @endif
            </div>
        @endif

    {{-- מספרי התקופה. --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem">
        @foreach ([
            ['נשלחו', number_format($totals['sent'])],
            ['נמסרו', number_format($totals['delivered'])],
            ['נפתחו', number_format($totals['opened'])],
            ['שיעור פתיחה', $totals['open_rate'] === null ? '—' : round($totals['open_rate'] * 100, 1).'%'],
            ['חזרו', number_format($totals['bounced'])],
        ] as [$label, $value])
            <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        לפי דיווחי ספק המייל על דיוורים מ-{{ $days }} הימים האחרונים. שיעור הפתיחה מחושב מתוך מה שנמסר —
        הודעה שחזרה מעולם לא הגיעה לאיש, וספירתה הייתה מציגה את התוכן ככישלון במקום את הכתובת.
    </p>

    {{-- ההמלצה: מתי לשלוח. --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <h3 class="text-sm font-semibold">מתי כדאי לשלוח</h3>
        @if ($best)
            <p class="mt-2 text-sm">
                רוב הפתיחות מתרכזות בין <strong>{{ $pad($best['from']) }}</strong> ל-<strong>{{ $pad($best['to']) }}</strong>
                — {{ round($best['share'] * 100) }}% מכלל הפתיחות בתקופה.
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                בעריכת דיוור, בקטע "תזמון", אפשר לקבוע שליחה אוטומטית לשעה הזו.
            </p>
        @else
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                אין עדיין מספיק פתיחות כדי להמליץ על שעה. כמה פתיחות מקריות אינן דפוס, והמלצה שנשענת עליהן
                גרועה מלא להמליץ.
            </p>
        @endif
    </div>

    {{-- פתיחות לפי שעה. עמודות CSS ולא ספריית גרפים: הנתון פשוט, והדף הזה לא
         צריך לטעון קוד חיצוני בשבילו. --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <h3 class="mb-3 text-sm font-semibold">פתיחות לפי שעה ביום</h3>
        <div style="display:flex;align-items:flex-end;gap:.2rem;height:9rem" role="img"
             aria-label="גרף פתיחות לפי שעה ביום">
            @foreach ($byHour as $hour => $opens)
                <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%"
                     title="{{ $pad($hour) }} — {{ $opens }} פתיחות">
                    <div @class(['rounded-t', 'bg-primary-500' => ! ($best && $hour >= $best['from'] && $hour < $best['from'] + 2), 'bg-success-500' => $best && $hour >= $best['from'] && $hour < $best['from'] + 2])
                         style="height:{{ max(2, (int) round($opens / $peakHour * 100)) }}%"></div>
                </div>
            @endforeach
        </div>
        <div style="display:flex;gap:.2rem;margin-top:.35rem">
            @foreach (array_keys($byHour) as $hour)
                <div style="flex:1;text-align:center;font-size:.6rem;color:#6b7280">
                    {{ $hour % 3 === 0 ? $hour : '' }}
                </div>
            @endforeach
        </div>
        {{-- אותם מספרים גם כטקסט: גרף בלבד אינו נגיש לקורא מסך. --}}
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            @foreach ($byHour as $hour => $opens)
                @if ($opens > 0){{ $pad($hour) }}: {{ $opens }}@if (! $loop->last) · @endif @endif
            @endforeach
            @if (array_sum($byHour) === 0)אין פתיחות בתקופה.@endif
        </p>
    </div>

    {{-- פתיחות לפי יום בשבוע. --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <h3 class="mb-3 text-sm font-semibold">פתיחות לפי יום בשבוע</h3>
        <div class="space-y-1.5">
            @foreach ($byWeekday as $day => $opens)
                <div class="flex items-center gap-2 text-xs">
                    <span style="width:3.5rem" class="text-gray-500 dark:text-gray-400">{{ $weekdayNames[$day] }}</span>
                    <div class="h-3 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded bg-primary-500"
                             style="width:{{ $opens === 0 ? 0 : max(2, (int) round($opens / $peakWeekday * 100)) }}%"></div>
                    </div>
                    <span style="width:2.5rem" class="text-left tabular-nums text-gray-500 dark:text-gray-400">{{ $opens }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- מי כבר לא פותח. --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">לקוחות שאינם פותחים</h3>
            <span class="text-xs {{ $skipping ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400' }}">
                {{ $skipping ? 'מדולגים אוטומטית בדיוור פרסומי' : 'הדילוג האוטומטי כבוי' }}
            </span>
        </div>

        @forelse ($nonOpeners as $customer)
            <div class="flex flex-wrap items-center gap-x-3 border-b border-gray-100 py-1.5 text-sm last:border-0 dark:border-gray-700">
                <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $customer]) }}"
                   class="font-medium text-primary-600 hover:underline">{{ $customer->name }}</a>
                <span class="text-xs text-gray-500 dark:text-gray-400" style="unicode-bidi:plaintext">{{ $customer->email }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">
                אין לקוח שקיבל כמה הודעות ולא פתח אף אחת. (הסף נקבע בהגדרות המערכת.)
            </p>
        @endforelse

        @if ($nonOpeners->isNotEmpty())
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                הם ממשיכים לקבל הודעות שירות — תחזוקה, אבטחה, שינוי בשירות — ומדולגים רק בדיוור פרסומי.
                בכל דיוור אפשר לכלול אותם בכל זאת, במתג שבקטע "קהל יעד".
                מי שיפתח הודעה כלשהי יוצא מהרשימה מעצמו.
            </p>
        @endif
    </div>
</x-filament-panels::page>
