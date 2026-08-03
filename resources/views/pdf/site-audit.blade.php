<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
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
        .item { border-right: 3px solid #cbd5e1; padding: 0 12px 0 0; margin-bottom: 13px; }
        .item.critical { border-right-color: #b91c1c; }
        .item.warning { border-right-color: #b45309; }
        .item.notice { border-right-color: #1d4ed8; }
        .item .title { font-weight: bold; font-size: 12.5px; }
        .item .area { color: #55606e; font-size: 10px; }
        .item .detail { margin-top: 3px; }
        .item .fix { margin-top: 4px; background: #f6f7f9; border-radius: 6px; padding: 6px 9px; }
        .item .fix b { color: #3730a3; }
        .item .evidence { margin-top: 3px; color: #55606e; font-size: 10px; direction: ltr; unicode-bidi: embed; text-align: right; }

        .passed { color: #166534; }
        .passed li { margin-bottom: 2px; }
        ul { margin: 4px 0; padding-right: 18px; }

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
            לא נמצאו ליקויים בבדיקות שבוצעו. פירוט מה שנבדק ונמצא תקין מופיע בהמשך.
        @else
            להלן {{ count($problems) }} ממצאים שנמצאו בבדיקה חיצונית של האתר, מסודרים לפי דחיפות.
            לכל ממצא מצורף הסבר מה המשמעות שלו ומה נדרש כדי לתקן.
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
                @if (! empty($item['fix']))
                    <div class="fix"><b>מה צריך לעשות:</b> {{ $item['fix'] }}</div>
                @endif
                @if (! empty($item['evidence']))
                    <div class="evidence">{{ $item['evidence'] }}</div>
                @endif
            </div>
        @endforeach
    @endforeach

    @if ($passed !== [])
        <h2>נבדק ונמצא תקין</h2>
        <ul class="passed">
            @foreach ($passed as $item)
                <li>{{ $item['title'] }}@if (! empty($item['detail'])) — {{ $item['detail'] }}@endif</li>
            @endforeach
        </ul>
    @endif

    <div class="foot">
        הבדיקה בוצעה מבחוץ, ללא גישה לניהול האתר, בתאריך {{ $checkedAt }} — בדיוק כפי שהאתר נראה למבקר ולמנועי החיפוש.
        היא מכסה זמינות, אבטחת התחברות, הגנות דפדפן, מהירות, נראות בגוגל, נגישות ותקינות הדומיין.
        אין בה כדי להעיד על מה שאינו נראה מבחוץ — תוכן מסדי הנתונים, גיבויים או קוד פנימי.
        ממצא שלא נבדק אינו ממצא תקין.
    </div>
</body>
</html>
