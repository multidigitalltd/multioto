<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>הוסרת מרשימת הדיוור</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e;
            --accent: #1f6feb; --accent-fg: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba; --accent: #4c8dff; --accent-fg: #06101f; }
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
        h1 { font-size: 1.3rem; margin: .5rem 0 1rem; }
        p { color: var(--muted); margin: 0 0 .9rem; line-height: 1.7; }
        .note {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            border-radius: 10px; padding: .85rem 1rem; text-align: start; margin: 1.25rem 0;
        }
        .undo {
            display: inline-block; margin-top: .35rem; padding: .7rem 1.5rem; border-radius: 10px;
            background: var(--accent); color: var(--accent-fg); text-decoration: none; font-weight: 600;
            border: 0; font: inherit; font-weight: 600; cursor: pointer;
        }
        .undo:hover { filter: brightness(1.08); }
        .undo:focus-visible { outline: 3px solid var(--fg); outline-offset: 3px; }
        a { color: var(--accent); }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
    <main>
        <div style="font-size:2.5rem" aria-hidden="true">✅</div>
        <h1>הוסרת מרשימת הדיוור</h1>

        <p>לא נשלח אליך יותר דיוור פרסומי. הבקשה נרשמה ותכובד מיד.</p>

        <div class="note">
            <p style="margin:0">
                <strong>שים לב:</strong> הודעות שירות ימשיכו להישלח אליך —
                חשבוניות, דרישות תשלום, והתראות על תקלה באתר שלך. אלה אינן
                פרסומת, והן נחוצות כדי שהשירות שלך ימשיך לפעול.
            </p>
        </div>

        <p>הוסרת בטעות?</p>
        {{-- A form, not a link: a crawler or link previewer that follows every
             URL on this page must not quietly put the customer back on the list. --}}
        <form method="POST" action="{{ $resubscribeUrl }}">
            @csrf
            <button type="submit" class="undo">החזירו אותי לרשימה</button>
        </form>

        @if ($support = config('billing.email.support_address'))
            <p style="margin-top:1.5rem">לכל שאלה: <a href="mailto:{{ $support }}">{{ $support }}</a></p>
        @endif
    </main>
</body>
</html>
