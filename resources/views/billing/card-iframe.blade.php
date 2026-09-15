<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>הזנת פרטי כרטיס אשראי — מולטי דיגיטל</title>
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
            display: flex; justify-content: center; align-items: flex-start;
            padding: clamp(.5rem, 3vw, 1.5rem);
        }
        main {
            background: var(--card); width: 100%; max-width: 48rem; border-radius: 16px;
            padding: clamp(.75rem, 3vw, 1.75rem); box-shadow: 0 4px 24px rgb(0 0 0 / .1);
        }
        h1 { font-size: clamp(1.1rem, 4vw, 1.3rem); margin: 0 0 .35rem; text-align: center; }
        p.lead { color: var(--muted); margin: 0 0 1rem; text-align: center; font-size: .95rem; }
        .frame-wrap {
            position: relative; border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
            background: var(--card);
        }
        /* Fill most of the viewport height so the hosted card form has room to
           breathe and never renders in a cramped, unreadable strip. */
        iframe { width: 100%; height: clamp(30rem, 80vh, 52rem); border: 0; display: block; }
        .secure { text-align: center; color: var(--muted); font-size: .85rem; margin-top: 1rem; }
        /* The agreed payment details, for a customer who pays by transfer or
           standing order: what they actually came for, kept in front of them
           rather than lost behind the card form. */
        .notice {
            border: 1px solid var(--border); border-radius: 12px; padding: .85rem 1rem;
            margin: 0 0 1rem; background: color-mix(in srgb, var(--bg) 60%, transparent);
        }
        .notice-head { font-weight: 600; margin-bottom: .35rem; }
        .notice-body { white-space: pre-line; color: var(--muted); font-size: .92rem; line-height: 1.6; }
        @media (max-width: 30rem) {
            main { border-radius: 12px; }
            p.lead { font-size: .9rem; }
        }
    </style>
</head>
<body>
    <main>
        @if ($logo = \App\Support\Branding::logoUrl())
            <div style="text-align:center;margin-bottom:.75rem;"><img src="{{ $logo }}" alt="לוגו" style="max-height:3rem;"></div>
        @endif
        @if (($securityCard ?? false) === true)
            <h1>כרטיס אשראי לביטחון</h1>
            <p class="lead">
                התשלום שלכם מתבצע ב{{ $methodLabel }} כפי שסוכם — הכרטיס הזה אינו מחויב באופן שוטף.
                @if (($fallbackDays ?? 0) > 0)
                    הוא משמש כביטחון בלבד: אם תשלום לא יגיע תוך {{ $fallbackDays }} יום ממועד הפירעון, נחייב אותו.
                @else
                    הוא נשמר כביטחון בלבד.
                @endif
            </p>

            @if (filled($paymentInstructions ?? null))
                <div class="notice">
                    <div class="notice-head">פרטי התשלום ב{{ $methodLabel }}</div>
                    {{-- Escaped, and newlines are preserved by CSS rather than by
                         markup — the text is operator-editable and never HTML. --}}
                    <div class="notice-body">{{ $paymentInstructions }}</div>
                </div>
            @endif
        @else
            <h1>הזנת פרטי כרטיס אשראי</h1>
            <p class="lead">הזינו את פרטי הכרטיס בטופס המאובטח למטה. הכרטיס נשמר אצל חברת הסליקה בלבד.</p>
        @endif
        <div class="frame-wrap">
            {{-- The card fields are served by Cardcom (PCI Level 1); we only frame them. --}}
            <iframe src="{{ $cardUrl }}" title="הזנת כרטיס אשראי מאובטחת"
                    allow="payment" referrerpolicy="no-referrer"></iframe>
        </div>
        <p class="secure">🔒 פרטי הכרטיס מוזנים ישירות מול חברת הסליקה (קארדקום). איננו רואים ואיננו שומרים את מספר הכרטיס.</p>
    </main>
</body>
</html>
