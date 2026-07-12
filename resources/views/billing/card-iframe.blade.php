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
            display: flex; justify-content: center; align-items: flex-start; padding: 1.5rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 34rem; border-radius: 16px;
            padding: clamp(1rem, 4vw, 1.75rem); box-shadow: 0 4px 24px rgb(0 0 0 / .1);
        }
        h1 { font-size: 1.3rem; margin: 0 0 .35rem; text-align: center; }
        p.lead { color: var(--muted); margin: 0 0 1rem; text-align: center; font-size: .95rem; }
        .frame-wrap {
            position: relative; border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
            min-height: 32rem; background: var(--card);
        }
        iframe { width: 100%; height: 40rem; border: 0; display: block; }
        .secure { text-align: center; color: var(--muted); font-size: .85rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <main>
        <h1>הזנת פרטי כרטיס אשראי</h1>
        <p class="lead">הזינו את פרטי הכרטיס בטופס המאובטח למטה. הכרטיס נשמר אצל חברת הסליקה בלבד.</p>
        <div class="frame-wrap">
            {{-- The card fields are served by Cardcom (PCI Level 1); we only frame them. --}}
            <iframe src="{{ $cardUrl }}" title="הזנת כרטיס אשראי מאובטחת"
                    allow="payment" referrerpolicy="no-referrer"></iframe>
        </div>
        <p class="secure">🔒 פרטי הכרטיס מוזנים ישירות מול חברת הסליקה (קארדקום). איננו רואים ואיננו שומרים את מספר הכרטיס.</p>
    </main>
</body>
</html>
