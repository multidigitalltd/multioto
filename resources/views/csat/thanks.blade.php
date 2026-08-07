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
        .google {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            margin: .5rem 0 1rem; padding: .85rem 1.5rem; border-radius: 10px;
            background: #1a73e8; color: #fff; text-decoration: none; font-weight: 600;
            font-size: 1rem; line-height: 1.2;
        }
        .google:hover, .google:focus { background: #1557b0; }
        /* Visible focus for keyboard users — the button is the whole point of
           this page for a happy customer, and a keyboard must be able to find it. */
        .google:focus-visible { outline: 3px solid #16181d; outline-offset: 3px; }
        @media (prefers-color-scheme: dark) {
            .google:focus-visible { outline-color: #f4f5f7; }
        }
        @media (prefers-reduced-motion: no-preference) {
            .google { transition: background-color .15s ease-in-out; }
        }
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
        {{-- Five stars, and only five: someone who has just said the service was
             perfect is the one person it is fair to ask for a public word. A
             four-star customer had a reservation, and asking them to publish it
             is asking for the review nobody wanted. Shown only when a link is
             actually configured — a button that goes nowhere is worse than none. --}}
        @if ((int) $ticket->csat_rating === 5 && filled($google = config('billing.support.csat.google_review_url')))
            <p>יעשה לנו את היום אם תשתפו גם אחרים 🙂</p>
            <a class="google" href="{{ $google }}" target="_blank" rel="noopener noreferrer">
                <span aria-hidden="true">⭐</span> דרגו אותנו בגוגל
            </a>
        @endif

        @if ($support = config('billing.email.support_address'))
            <p>צריך עוד עזרה? כתבו לנו: <a href="mailto:{{ $support }}">{{ $support }}</a></p>
        @endif
    </main>
</body>
</html>
