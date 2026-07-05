<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ request()->route('result') === 'success' ? 'הפרטים עודכנו בהצלחה' : 'העדכון לא הושלם' }} — מולטי דיגיטל</title>
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
        @media (prefers-reduced-motion: no-preference) {
            main { animation: rise .3s ease-out; }
            @keyframes rise { from { opacity: 0; transform: translateY(6px); } }
        }
    </style>
</head>
<body>
    <main>
        @if (request()->route('result') === 'success')
            <h1>הפרטים עודכנו בהצלחה ✅</h1>
            <p>תודה! אם היה חיוב ממתין, ננסה לבצע אותו אוטומטית בדקות הקרובות ונשלח חשבונית למייל.</p>
        @else
            <h1>העדכון לא הושלם</h1>
            <p>עדכון פרטי הכרטיס לא הסתיים בהצלחה. אפשר לנסות שוב דרך הקישור שקיבלתם, או לפנות אלינו בוואטסאפ ונעזור מיד.</p>
        @endif
    </main>
</body>
</html>
