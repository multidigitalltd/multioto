<!doctype html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>נקלטת בהצלחה!</title>
    <style>
        :root { --brand: #4f46e5; --muted: #6b7280; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; background: #f8fafc; margin: 0; color: #111827; }
        main { max-width: 34rem; margin: 8vh auto; padding: 2.5rem 2rem; background: #fff; border-radius: 1rem; box-shadow: 0 8px 30px rgb(0 0 0 / .06); text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { color: var(--muted); line-height: 1.7; margin: 0 0 .5rem; }
        .check { font-size: 3rem; line-height: 1; margin-bottom: 1rem; }
        @media (prefers-reduced-motion: no-preference) { .check { animation: pop .4s ease-out; } @keyframes pop { from { transform: scale(.6); opacity: 0; } } }
        .instructions { text-align: start; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: .75rem; padding: 1rem 1.1rem; margin-top: 1.25rem; white-space: pre-line; color: #111827; }
        .instructions .head { font-weight: 700; margin-bottom: .35rem; }
        .instructions a { color: var(--brand); word-break: break-all; }
    </style>
</head>
<body>
    <main>
        <div class="check" aria-hidden="true">✅</div>
        <h1>הפרטים נקלטו בהצלחה!</h1>
        <p>תודה שהצטרפת אלינו. שלחנו לך מייל "ברוכים הבאים" עם כל הפרטים.</p>
        <p>ניצור איתך קשר בהקדם להשלמת הסדר התשלום שבחרת — ומשם הכול כבר רץ אוטומטית.</p>

        @if (session('payment_instructions'))
            <div class="instructions">
                <div class="head">{{ session('payment_method_label') }} — הוראות להשלמה</div>
                {{-- Escape first, then linkify URLs (e.g. the Kesher authorisation link). --}}
                {!! preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', e(session('payment_instructions'))) !!}
            </div>
        @endif
    </main>
</body>
</html>
