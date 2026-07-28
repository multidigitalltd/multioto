<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>חזרת לרשימת הדיוור</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e; --accent: #1f6feb;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba; --accent: #4c8dff; }
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
        h1 { font-size: 1.3rem; margin: .5rem 0 1rem; }
        p { color: var(--muted); margin: 0 0 .75rem; line-height: 1.7; }
        a { color: var(--accent); }
    </style>
</head>
<body>
    <main>
        <div style="font-size:2.5rem" aria-hidden="true">🎉</div>
        <h1>חזרת לרשימת הדיוור</h1>
        <p>נמשיך לעדכן אותך. תמיד אפשר להסיר שוב מכל הודעה שנשלח.</p>

        @if ($support = config('billing.email.support_address'))
            <p>לכל שאלה: <a href="mailto:{{ $support }}">{{ $support }}</a></p>
        @endif
    </main>
</body>
</html>
