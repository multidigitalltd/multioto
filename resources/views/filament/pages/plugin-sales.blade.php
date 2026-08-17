<x-filament-panels::page>
    @php
        $ils = fn (int $agorot): string => \App\Support\Money::ils($agorot);
    @endphp

    {{-- עוד לא נמכר דבר. קיר של אפסים כאן היה נקרא כעסק שנכשל, בזמן שהוא פשוט
         עוד לא התחיל — ושתי המסקנות מובילות לפעולות הפוכות. --}}
    @unless ($started)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900"
             role="status">
            <p class="font-semibold text-gray-900 dark:text-gray-100">עדיין לא הונפק אף רישיון</p>
            <p class="mt-1 text-gray-600 dark:text-gray-400">
                @if ($overview['productsSellable'] > 0)
                    יש {{ $overview['productsSellable'] }} תוספים עם מסלול פעיל. הפנו לקוח לעמוד המכירה של התוסף,
                    או הנפיקו רישיון ידנית ממסך הרישיונות — המספרים כאן יתמלאו מעצמם.
                @else
                    כדי למכור, צריך תוסף עם מסלול מחיר פעיל אחד לפחות. הוסיפו מסלול במסך "תוספים למכירה".
                @endif
            </p>
        </div>
    @endunless

    {{-- כסף ששולם ולא קיבל רישיון. אמור להיות ריק תמיד, וזו בדיוק הסיבה שהוא
         ראשון: מילוי שנכשל בשקט אינו נראה עד שהקונה כותב. --}}
    @if ($unfulfilled->isNotEmpty())
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm dark:border-red-500/40 dark:bg-red-500/10"
             role="alert">
            <p class="font-semibold text-red-900 dark:text-red-200">
                {{ $unfulfilled->count() }} הזמנות שולמו ולא קיבלו רישיון
            </p>
            <p class="mt-1 text-red-900 dark:text-red-200">
                הכסף נגבה והמפתח לא הונפק. טפלו בזה עכשיו — הקונה שילם וקיבל כלום.
            </p>
            <ul class="mt-2 space-y-1 text-red-900 dark:text-red-200">
                @foreach ($unfulfilled as $order)
                    <li>
                        {{ $order->buyer_email }} · {{ $order->product?->name ?? 'תוסף' }} ·
                        {{ $ils((int) $order->total_agorot) }} · {{ $order->created_at->format('d/m/Y H:i') }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">רישיונות פעילים</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $overview['active'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                מתוך {{ $overview['total'] }} שהונפקו · {{ $overview['expired'] }} פגו · {{ $overview['revoked'] }} בוטלו
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">אתרים שמריצים את התוסף</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $overview['sitesLive'] }}</p>
            {{-- הרשום לבדו משקר כלפי מעלה: התקנה שנמחקה משאירה שורה. --}}
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                מתוך {{ $overview['sites'] }} רשומים · "מריץ" = נראה ב-{{ $staleDays }} הימים האחרונים
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">נגבה ב-{{ $windowDays }} הימים האחרונים</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $ils($revenue['agorot']) }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $revenue['count'] }} חיובים · בתקופה הקודמת {{ $ils($revenue['previousAgorot']) }}
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">חידושים שנגבו</p>
            {{-- null ≠ 0%. "לא היו חידושים" ו"כל החידושים נכשלו" הם שני מצבים
                 שונים לגמרי, ואסור להם להיראות אותו דבר. --}}
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                {{ $renewals['rate'] === null ? '—' : $renewals['rate'].'%' }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                @if ($renewals['rate'] === null)
                    לא היו חידושים לגבות בתקופה הזו
                @else
                    {{ $renewals['succeeded'] }} נגבו · {{ $renewals['failed'] }} נכשלו
                    @if ($renewals['open'] > 0) · {{ $renewals['open'] }} בתהליך @endif
                @endif
            </p>
        </div>
    </div>

    @if ($renewals['failed'] > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
            <p class="text-amber-900 dark:text-amber-200">
                {{ $renewals['failed'] }} חידושים נכשלו ב-{{ $windowDays }} הימים האחרונים, בסך
                <strong>{{ $ils($renewals['lostAgorot']) }}</strong>. הם נמצאים בסולם הדאנינג ככל מנוי אחר —
                מסך הגבייה מציג אותם לצד השאר.
            </p>
        </div>
    @endif

    @if ($byProduct->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">לפי תוסף</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-gray-600 dark:text-gray-400">
                            <th scope="col" class="py-2 text-right font-medium">תוסף</th>
                            <th scope="col" class="py-2 text-right font-medium">רישיונות פעילים</th>
                            <th scope="col" class="py-2 text-right font-medium">סה״כ הונפקו</th>
                            <th scope="col" class="py-2 text-right font-medium">נגבה ({{ $windowDays }} ימים)</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-900 dark:text-gray-100">
                        @foreach ($byProduct as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2">{{ $row['name'] }}</td>
                                <td class="py-2">{{ $row['active'] }}</td>
                                <td class="py-2">{{ $row['total'] }}</td>
                                <td class="py-2">{{ $ils($row['agorot']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">פג ולא חודש</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                רישיונות שהעדכונים בהם נגמרו ב-{{ $windowDays }} הימים האחרונים. התוסף עצמו ממשיך לעבוד אצל הלקוח.
            </p>

            @if ($lapsed->isEmpty())
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">אין — אף רישיון לא פג בתקופה הזו.</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($lapsed as $license)
                        <li class="border-t border-gray-100 pt-2 dark:border-white/5">
                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $license->customer?->name ?? $license->email ?? 'ללא לקוח' }}
                            </span>
                            <span class="text-gray-600 dark:text-gray-400">
                                · {{ $license->product?->name ?? 'תוסף' }} · פג {{ $license->expires_at->format('d/m/Y') }}
                            </span>
                            {{-- הסיבה חשובה יותר מהעובדה: רכישה חד-פעמית שהגיעה לסופה
                                 אינה נטישה, ומנוי שהדאנינג ויתר עליו כן. --}}
                            <span class="text-gray-500 dark:text-gray-400">
                                — @if ($license->subscription === null)
                                    ללא מנוי מתחדש (חד-פעמי)
                                @else
                                    מנוי {{ $license->subscription->status?->getLabel() ?? 'לא ידוע' }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">פג בקרוב ואין מה שיחדש</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $soonDays }} הימים הקרובים, רק רישיונות ללא מנוי מתחדש — אלה שדורשים פנייה יזומה.
            </p>

            @if ($expiringSoon->isEmpty())
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">אין. כל מה שפג בקרוב מתחדש מעצמו.</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($expiringSoon as $license)
                        <li class="border-t border-gray-100 pt-2 dark:border-white/5">
                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $license->customer?->name ?? $license->email ?? 'ללא לקוח' }}
                            </span>
                            <span class="text-gray-600 dark:text-gray-400">
                                · {{ $license->product?->name ?? 'תוסף' }} · עד {{ $license->expires_at->format('d/m/Y') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-filament-panels::page>
