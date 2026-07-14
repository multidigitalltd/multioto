<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>קישור תשלום — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e; --border: #c2c8d0;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba; --border: #37415a; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            display: flex; justify-content: center; align-items: center; padding: 1.5rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 30rem; border-radius: 16px;
            padding: clamp(1.25rem, 4vw, 2rem); box-shadow: 0 4px 24px rgb(0 0 0 / .1); text-align: center;
        }
        h1 { font-size: 1.25rem; margin: .5rem 0 .5rem; }
        p { color: var(--muted); margin: 0 0 .75rem; line-height: 1.6; }
        .icon { font-size: 2.5rem; }
    </style>
</head>
<body>
    <main>
        @if ($paid)
            <div class="icon">✅</div>
            <h1>התשלום כבר בוצע</h1>
            <p>קישור זה כבר שולם — אין צורך בפעולה נוספת. תודה!</p>
        @else
            <div class="icon">🔒</div>
            <h1>קישור התשלום אינו פעיל</h1>
            <p>הקישור הזה בוטל או שאינו זמין עוד. אם אתם מצפים לשלם, פנו אלינו ונשלח קישור חדש.</p>
        @endif
        @if ($support = config('billing.email.support_address'))
            <p>לתמיכה: <a href="mailto:{{ $support }}">{{ $support }}</a></p>
        @endif
    </main>
</body>
</html>
