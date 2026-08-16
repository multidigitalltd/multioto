<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>הרכישה שלך — {{ $order->product?->name }}</title>
    <meta name="robots" content="noindex">
    {{-- The licence is issued by the money arriving, not by this browser coming
         back — and the payment provider returns the buyer before its webhook
         reaches us. A few seconds of polite refreshing beats a page that says
         "not found" to somebody who just paid. --}}
    @unless ($order->isFulfilled())
        <meta http-equiv="refresh" content="5">
    @endunless
    <style>
        :root {
            color-scheme: light dark;
            --bg: #2b2b30; --card: #34343a; --fg: #f4f5f7; --muted: #b4bac4;
            --border: #4a4a52; --brand: #ec4899; --ok: #4ade80; --warn: #fbbf24;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main { background: var(--card); width: 100%; max-width: 38rem; border-radius: 16px;
               padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: 0 8px 32px rgb(0 0 0 / .3); }
        h1 { font-size: clamp(1.4rem, 5vw, 1.9rem); margin: 0 0 .5rem; }
        p { margin: .6rem 0; }
        .muted { color: var(--muted); font-size: .95rem; }
        .note { border-radius: 10px; padding: .8rem 1rem; margin: 1rem 0; }
        .note.ok { background: rgb(74 222 128 / .12); border: 1px solid var(--ok); }
        .note.warn { background: rgb(251 191 36 / .12); border: 1px solid var(--warn); }
        ol { padding-inline-start: 1.2rem; }
        a.download { display: inline-block; background: var(--brand); color: #fff; text-decoration: none;
                     padding: .8rem 1.2rem; border-radius: 10px; font-weight: 700; }
        a.download:hover { filter: brightness(1.08); }
        a:focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
<main>
    @if ($order->isFulfilled())
        <h1>תודה! הרישיון שלך מוכן ✅</h1>

        <p>מפתח הרישיון ל<strong>{{ $order->product?->name }}</strong> נשלח גם לכתובת
           <strong>{{ $order->buyer_email }}</strong>.</p>

        {{-- The key is not shown here, and that is not an oversight: it is
             never stored in a form anybody can read back, so the email is the
             only copy. Printing it on a page that survives in a browser's
             history would undo exactly that. --}}
        <div class="note ok">
            <strong>שמרו את המייל ששלחנו — הוא העותק היחיד של המפתח.</strong>
            המפתח אינו נשמר אצלנו ואנחנו לא יכולים לשחזר אותו; אם יאבד, ננפיק חדש והישן יפסיק לעבוד.
        </div>

        @if ($order->product?->currentRelease())
            <p><a class="download" href="{{ route('store.download', ['reference' => $order->reference]) }}">
                הורדת {{ $order->product->name }} (ZIP)
            </a></p>
        @endif

        <h2 style="font-size:1.1rem">איך מתקינים</h2>
        <ol>
            <li>הורידו את קובץ ה-ZIP מכאן או מהמייל ששלחנו.</li>
            <li>בלוח הבקרה של האתר: תוספים ← הוסף תוסף ← העלה תוסף.</li>
            <li>אחרי ההפעלה: הגדרות התוסף ← רישיון, הדביקו את המפתח ולחצו "הפעלת רישיון".</li>
            <li>מכאן עדכוני גרסה יגיעו אליכם אוטומטית, כמו כל תוסף אחר.</li>
        </ol>
    @elseif ($order->status === \App\Models\PluginOrder::FAILED)
        <h1>הרכישה לא הושלמה</h1>
        <p>לא בוצע חיוב. אפשר לנסות שוב מעמוד המוצר, ואם משהו נתקע — כתבו לנו ונשלים ידנית.</p>
    @else
        {{-- The honest middle state. Saying "failed" here would be a lie, and
             saying "done" would be a promise we have not yet kept. --}}
        <h1>קיבלנו את התשלום — המפתח בדרך</h1>
        <p>אנחנו מאשרים את התשלום מול חברת הסליקה. זה לוקח בדרך כלל כמה שניות,
           והעמוד הזה יתעדכן מעצמו.</p>
        <p class="muted">מפתח הרישיון יישלח לכתובת <strong>{{ $order->buyer_email }}</strong> ברגע שהאישור מתקבל.
           אם לא הגיע דבר תוך כמה דקות — כתבו לנו וצרפו את המספר הזה:
           <span style="direction:ltr;display:inline-block">{{ Str::limit($order->reference, 13, '') }}</span></p>
    @endif
</main>
</body>
</html>
