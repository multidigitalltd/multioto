<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>תודה על הדירוג — פנייה #{{ $ticket->id }}</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba; }
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
        h1 { font-size: 1.3rem; margin: .5rem 0; }
        p { color: var(--muted); margin: 0 0 .75rem; line-height: 1.6; }
        .rating { font-size: 1.75rem; letter-spacing: .15rem; }
    </style>
</head>
<body>
    <main>
        <div style="font-size:2.5rem" aria-hidden="true">🙏</div>
        <h1>תודה על המשוב!</h1>
        <p>קיבלנו את הדירוג שלך לפנייה #{{ $ticket->id }}. זה עוזר לנו להשתפר עבורך.</p>
        <p class="rating" aria-label="דירוג {{ $ticket->csat_rating }} מתוך 5">
            {{ str_repeat('★', (int) $ticket->csat_rating) }}{{ str_repeat('☆', 5 - (int) $ticket->csat_rating) }}
        </p>
        @if ($support = config('billing.email.support_address'))
            <p>צריך עוד עזרה? כתבו לנו: <a href="mailto:{{ $support }}">{{ $support }}</a></p>
        @endif
    </main>
</body>
</html>
