<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- `result` arrives either as a route parameter (Cardcom's success
         redirect) or from the controller that looks up why a card was refused. --}}
    @php($outcome = $result ?? request()->route('result'))
    <title>{{ $outcome === 'success' ? 'הפרטים עודכנו בהצלחה' : 'העדכון לא הושלם' }} — מולטי דיגיטל</title>
    {{-- Cardcom redirects the iframe here when done. Break out so the result is
         shown full-screen instead of trapped inside the small frame. --}}
    <script>
        if (window.top !== window.self) {
            window.top.location.replace(window.location.href);
        }
    </script>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            display: flex; min-height: 100vh; margin: 0;
            align-items: center; justify-content: center;
            background: #f6f7f9; color: #16181d;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #16181d; color: #f6f7f9; }
            main { background: #23262d !important; }
        }
        main {
            background: #fff; border-radius: 12px; padding: 2.5rem;
            max-width: 28rem; margin: 1rem; text-align: center;
            box-shadow: 0 2px 12px rgb(0 0 0 / .08);
        }
        h1 { font-size: 1.4rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; margin: 0; }
        p + p { margin-top: .75rem; }
        .reason {
            background: #fdf0f0; color: #7a1f1f; border: 1px solid #f0c9c9;
            border-radius: 8px; padding: .75rem 1rem; font-weight: 600;
        }
        @media (prefers-color-scheme: dark) {
            .reason { background: #3a2224; color: #ffc9c9; border-color: #5c3234; }
        }
        @media (prefers-reduced-motion: no-preference) {
            main { animation: rise .3s ease-out; }
            @keyframes rise { from { opacity: 0; transform: translateY(6px); } }
        }
    </style>
</head>
<body>
    <main>
        @if ($outcome === 'success')
            <h1>הפרטים עודכנו בהצלחה ✅</h1>
            <p>תודה! אם היה חיוב ממתין, ננסה לבצע אותו אוטומטית בדקות הקרובות ונשלח חשבונית למייל.</p>
        @else
            <h1>העדכון לא הושלם</h1>
            {{-- The reason comes from Cardcom and is written for the card
                 holder — "your card company refused, call them" is the one
                 sentence that lets somebody actually solve this. Escaped like
                 any other external text. --}}
            @if (filled($reason ?? null))
                <p class="reason">{{ $reason }}</p>
                <p>אפשר לנסות שוב דרך אותו קישור עם כרטיס אחר, או לפנות אלינו בוואטסאפ ונעזור מיד.</p>
            @else
                <p>עדכון פרטי הכרטיס לא הסתיים בהצלחה. אפשר לנסות שוב דרך הקישור שקיבלתם, או לפנות אלינו בוואטסאפ ונעזור מיד.</p>
            @endif
        @endif
    </main>
</body>
</html>
