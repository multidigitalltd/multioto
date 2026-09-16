<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>הקישור אינו פעיל — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e; --border: #c2c8d0; --brand: #2563eb;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba; --border: #37415a; --brand: #60a5fa; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            display: flex; justify-content: center; align-items: center; padding: 1.5rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 32rem; border-radius: 16px;
            padding: clamp(1.25rem, 4vw, 2rem); box-shadow: 0 4px 24px rgb(0 0 0 / .1); text-align: center;
        }
        h1 { font-size: 1.25rem; margin: .5rem 0; }
        p { color: var(--muted); margin: 0 0 .75rem; line-height: 1.6; }
        .icon { font-size: 2.5rem; }
        a.button {
            display: inline-block; margin-top: .5rem; padding: .7rem 1.4rem; border-radius: 10px;
            background: var(--brand); color: #fff; text-decoration: none; font-weight: 600;
        }
        a.button:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        .notice {
            border: 1px solid var(--border); border-radius: 12px; padding: .85rem 1rem;
            margin: 1rem 0; text-align: start;
        }
        .notice-body { white-space: pre-line; color: var(--muted); font-size: .92rem; line-height: 1.6; }
    </style>
</head>
<body>
    <main>
        <div class="icon">⌛</div>
        <h1>הקישור אינו פעיל יותר</h1>
        {{-- Says the true thing rather than a generic error: nothing was lost
             on our side, but nothing was opened either, and the way forward is
             to fill the form again. --}}
        <p>ההרשמה לא הושלמה בזמן והקישור פג. אפשר למלא את הטופס מחדש — זה לוקח דקה.</p>
        <a class="button" href="{{ route('signup') }}">למילוי הטופס</a>
        @if ($support = config('billing.email.support_address'))
            <p>לתמיכה: <a href="mailto:{{ $support }}">{{ $support }}</a></p>
        @endif
    </main>
</body>
</html>
