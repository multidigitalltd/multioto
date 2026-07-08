<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>מולטי דיגיטל — מערכת תפעול</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #0f1729; --card: #ffffff; --fg: #0f172a;
            --muted: #475569; --brand: #4f46e5; --brand2: #7c3aed; --ring: rgb(79 70 229 / .4);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; color: #e2e8f0;
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            background: radial-gradient(1200px 600px at 80% -10%, #26346b 0%, transparent 60%),
                        radial-gradient(900px 500px at 0% 10%, #3b1d6e 0%, transparent 55%),
                        var(--bg);
            display: flex; flex-direction: column;
        }
        a { color: inherit; text-decoration: none; }
        header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.25rem clamp(1rem, 5vw, 3rem); max-width: 72rem; width: 100%; margin: 0 auto;
        }
        .brand { display: flex; align-items: center; gap: .6rem; font-weight: 700; font-size: 1.15rem; }
        .logo {
            width: 2.2rem; height: 2.2rem; border-radius: .6rem; display: grid; place-items: center;
            background: linear-gradient(135deg, var(--brand), var(--brand2)); color: #fff; font-weight: 800;
        }
        .top-link {
            font-size: .95rem; color: #c7d2fe; padding: .5rem .9rem; border-radius: .6rem;
            border: 1px solid rgb(199 210 254 / .25);
        }
        .top-link:hover { background: rgb(199 210 254 / .1); }
        main {
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 2rem clamp(1rem, 5vw, 3rem) 3rem; max-width: 72rem; margin: 0 auto; width: 100%;
        }
        .eyebrow {
            display: inline-block; font-size: .85rem; letter-spacing: .02em; color: #a5b4fc;
            background: rgb(129 140 248 / .12); border: 1px solid rgb(129 140 248 / .25);
            padding: .35rem .8rem; border-radius: 999px; margin-bottom: 1.5rem;
        }
        h1 {
            font-size: clamp(2rem, 6vw, 3.4rem); line-height: 1.15; margin: 0 0 1rem; font-weight: 800;
            background: linear-gradient(180deg, #fff, #c7d2fe); -webkit-background-clip: text;
            background-clip: text; color: transparent;
        }
        .sub { font-size: clamp(1rem, 2.4vw, 1.25rem); color: #b4c0d8; max-width: 40rem; margin: 0 auto 2.5rem; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1.25rem; width: 100%; max-width: 46rem; }
        .card {
            background: var(--card); color: var(--fg); border-radius: 1rem; padding: 1.75rem 1.5rem;
            text-align: right; box-shadow: 0 10px 40px rgb(0 0 0 / .25); border: 1px solid rgb(255 255 255 / .6);
            display: flex; flex-direction: column; gap: .5rem; transition: transform .15s, box-shadow .15s;
        }
        @media (prefers-reduced-motion: no-preference) {
            .card:hover { transform: translateY(-4px); box-shadow: 0 16px 50px rgb(0 0 0 / .35); }
        }
        .card .ico { width: 2.8rem; height: 2.8rem; border-radius: .7rem; display: grid; place-items: center; font-size: 1.4rem; margin-bottom: .4rem; }
        .card.team .ico { background: rgb(79 70 229 / .12); }
        .card.support .ico { background: rgb(13 148 136 / .12); }
        .card h2 { margin: 0; font-size: 1.25rem; }
        .card p { margin: 0; color: var(--muted); font-size: .95rem; line-height: 1.5; }
        .card .go { margin-top: .75rem; font-weight: 700; color: var(--brand); display: inline-flex; align-items: center; gap: .35rem; }
        .card.support .go { color: #0d9488; }
        .card:focus-visible { outline: 3px solid var(--ring); outline-offset: 3px; }
        footer { text-align: center; padding: 1.5rem; color: #64748b; font-size: .85rem; }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <span class="logo">M</span>
            <span>מולטי דיגיטל</span>
        </div>
        <a class="top-link" href="/admin">כניסת צוות ←</a>
    </header>

    <main>
        <span class="eyebrow">מערכת תפעול · חיובים · תמיכה · ניטור</span>
        <h1>הפלטפורמה שמנהלת<br>את כל העסק הדיגיטלי שלכם</h1>
        <p class="sub">
            מנויים וחיובים אוטומטיים, חשבוניות, תמיכה בוואטסאפ ובמייל, וניטור אתרים —
            הכול במקום אחד.
        </p>

        <div class="cards">
            <a class="card team" href="/admin" aria-label="כניסת צוות למערכת הניהול">
                <span class="ico">🔐</span>
                <h2>כניסת צוות</h2>
                <p>ניהול לקוחות, מנויים, חיובים ותמיכה — לוח הבקרה המלא.</p>
                <span class="go">כניסה למערכת ←</span>
            </a>

            <a class="card support" href="{{ route('support.form') }}" aria-label="פנייה לתמיכה">
                <span class="ico">💬</span>
                <h2>תמיכה ופנייה</h2>
                <p>יש שאלה או תקלה? השאירו פנייה ונחזור אליכם בהקדם.</p>
                <span class="go">שליחת פנייה ←</span>
            </a>

            <a class="card join" href="{{ route('signup') }}" aria-label="הצטרפות כלקוח חדש">
                <span class="ico">✨</span>
                <h2>הצטרפות כלקוח</h2>
                <p>בוחרים מסלול, ממלאים פרטים ומתחילים — הכול אונליין.</p>
                <span class="go">להצטרפות ←</span>
            </a>
        </div>
    </main>

    <footer>© {{ date('Y') }} מולטי דיגיטל · כל הזכויות שמורות</footer>
</body>
</html>
