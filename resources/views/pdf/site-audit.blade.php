<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        /* mPDF's own family name — anything else leaves its script-to-font
           switcher in charge, and that picks a Hebrew face with no bold. */
        * { font-family: dejavusans, sans-serif; }
        body { direction: rtl; color: #16181d; font-size: 12px; line-height: 1.65; margin: 0; padding: 26px 30px; }
        .head { border-bottom: 2px solid #4f46e5; padding-bottom: 14px; margin-bottom: 18px; text-align: center; }
        .head img { max-height: 56px; margin-bottom: 8px; }
        h1 { font-size: 21px; margin: 4px 0 2px; }
        .sub { color: #55606e; font-size: 11px; }
        .site { font-size: 14px; font-weight: bold; margin-top: 6px; direction: ltr; unicode-bidi: embed; }

        .lead { background: #f6f7f9; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        .tally td { padding: 4px 0 4px 18px; font-size: 12px; }
        .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-left: 6px; }
        .critical .dot, .dot.critical { background: #b91c1c; }
        .warning .dot, .dot.warning { background: #b45309; }
        .notice .dot, .dot.notice { background: #1d4ed8; }
        .ok .dot, .dot.ok { background: #15803d; }

        h2 { font-size: 14px; margin: 20px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; }
        .item { border-right: 3px solid #cbd5e1; padding: 1px 12px 3px 0; margin-bottom: 16px; }
        .item.critical { border-right-color: #b91c1c; }
        .item.warning { border-right-color: #b45309; }
        .item.notice { border-right-color: #1d4ed8; }
        .item .title { font-weight: bold; font-size: 14px; color: #16181d; }
        .item.critical .title { color: #991b1b; }
        .item.warning .title { color: #92400e; }
        .item .area { color: #55606e; font-size: 10px; margin-top: 1px; }
        .item .detail { margin-top: 4px; }
        .item .evidence { margin-top: 3px; color: #55606e; font-size: 10px; direction: ltr; unicode-bidi: embed; text-align: right; }

        .item.ok { border-right-color: #15803d; }
        .item.ok .title { color: #166534; }

        .passed-head { background: #f0fdf4; border-radius: 8px; padding: 10px 14px; margin: 20px 0 12px; }
        .passed-head .h { font-weight: bold; font-size: 13px; color: #166534; }
        .area-head { font-weight: bold; font-size: 11.5px; color: #55606e; margin: 12px 0 5px; }
        ul { margin: 4px 0; padding-right: 18px; }

        .since { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
        .since .h { font-weight: bold; font-size: 13px; margin-bottom: 4px; }
        .since .fixed-head { font-weight: bold; color: #166534; margin-top: 6px; }
        .since .new-head { font-weight: bold; color: #991b1b; margin-top: 6px; }

        .foot { margin-top: 24px; color: #55606e; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="head">
        @if ($logo)
            <img src="{{ $logo }}" alt="לוגו">
        @endif
        <h1>בדיקת אתר</h1>
        <div class="sub">{{ $company }} · הופק בתאריך {{ $generatedAt }}</div>
        <div class="site">{{ $audit->url }}</div>
    </div>

    <div class="lead">
        @if ($problems === [])
            לא נמצאו ליקויים בבדיקות שבוצעו@if ($passedCount > 0), ו-{{ $passedCount }} בדיקות עברו בהצלחה@endif.
            הפירוט המלא מופיע בהמשך.
        @else
            להלן {{ count($problems) }} ממצאים שנמצאו בבדיקה חיצונית של האתר, מסודרים לפי דחיפות.
            לכל ממצא מצורף הסבר מה המשמעות שלו עבור העסק.
            @if ($passedCount > 0)
                בסוף המסמך מפורטות {{ $passedCount }} הבדיקות שהאתר עבר בהצלחה.
            @endif
        @endif

        <table class="tally">
            <tr>
                <td><span class="dot critical"></span> דורש טיפול מיידי: {{ $counts['critical'] ?? 0 }}</td>
                <td><span class="dot warning"></span> חשוב לתקן: {{ $counts['warning'] ?? 0 }}</td>
                <td><span class="dot notice"></span> מומלץ לשפר: {{ $counts['notice'] ?? 0 }}</td>
                <td><span class="dot ok"></span> נבדק ותקין: {{ $counts['ok'] ?? 0 }}</td>
            </tr>
        </table>
    </div>

    {{-- מה השתנה מאז הבדיקה הקודמת. מופיע רק כשיש בדיקה קודמת להשוות אליה,
         ולפני הממצאים — מי שכבר קיבל דוח על האתר הזה שואל קודם כול מה זז. --}}
    @if ($comparison !== null)
        <div class="since">
            <div class="h">מאז הבדיקה הקודמת ({{ $comparison['at'] }})</div>

            @if ($comparison['fixed'] === [] && $comparison['appeared'] === [])
                אף ממצא לא נפתר ולא נוסף בין שתי הבדיקות.
            @else
                @if ($comparison['fixed'] !== [])
                    <div class="fixed-head">תוקנו — {{ count($comparison['fixed']) }}</div>
                    <ul>
                        @foreach ($comparison['fixed'] as $item)
                            <li>{{ $item['title'] }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($comparison['appeared'] !== [])
                    <div class="new-head">חדשים — {{ count($comparison['appeared']) }}</div>
                    <ul>
                        @foreach ($comparison['appeared'] as $item)
                            <li>{{ $item['title'] }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    @endif

    @foreach ($groups as $label => $items)
        @continue($items === [])
        <h2>{{ $label }}</h2>

        @foreach ($items as $item)
            <div class="item {{ $item['severity'] }}">
                <div class="title">{{ $item['title'] }}</div>
                <div class="area">{{ $item['area'] ?? '' }}</div>
                @if (! empty($item['detail']))
                    <div class="detail">{{ $item['detail'] }}</div>
                @endif
                @if (! empty($item['evidence']))
                    <div class="evidence">{{ $item['evidence'] }}</div>
                @endif
            </div>
        @endforeach
    @endforeach

    @if ($passed !== [])
        <div class="passed-head">
            <div class="h">נבדק ונמצא תקין — {{ $passedCount }} {{ $passedCount === 1 ? 'בדיקה' : 'בדיקות' }}</div>
            <div>אלה הדברים שנבדקו ונמצאו במצב טוב. הם מופיעים כאן במפורש כדי שיהיה ברור מה כן עובד באתר, ולא רק מה דורש טיפול.</div>
        </div>

        @foreach ($passed as $area => $items)
            <div class="area-head">{{ $area }}</div>

            @foreach ($items as $item)
                <div class="item ok">
                    <div class="title">{{ $item['title'] }}</div>
                    @if (! empty($item['detail']))
                        <div class="detail">{{ $item['detail'] }}</div>
                    @endif
                </div>
            @endforeach
        @endforeach
    @endif

    <div class="foot">
        הבדיקה בוצעה מבחוץ, ללא גישה לניהול האתר, בתאריך {{ $checkedAt }} — בדיוק כפי שהאתר נראה למבקר ולמנועי החיפוש.
        @if ($areas !== [])
            התחומים שנבדקו: {{ implode(' · ', $areas) }}.
        @endif
        אין בה כדי להעיד על מה שאינו נראה מבחוץ — תוכן מסדי הנתונים, גיבויים או קוד פנימי.
        ממצא שלא נבדק אינו ממצא תקין.
    </div>
</body>
</html>
