<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>אימות דו-שלבי — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e;
            --border: #c2c8d0; --primary: #4f46e5; --primary-fg: #ffffff; --error: #b42318;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba;
                --border: #37415a; --primary: #6366f1; --error: #f97066; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            display: flex; justify-content: center; align-items: center; padding: 1.5rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 26rem; border-radius: 16px;
            padding: clamp(1.5rem, 5vw, 2.25rem); box-shadow: 0 4px 24px rgb(0 0 0 / .1);
        }
        .icon { font-size: 2.25rem; text-align: center; }
        h1 { font-size: 1.3rem; margin: .5rem 0 .35rem; text-align: center; }
        p.lead { color: var(--muted); margin: 0 0 1.25rem; line-height: 1.6; text-align: center; }
        .dest { color: var(--fg); font-weight: 600; unicode-bidi: plaintext; }
        label { display: block; font-weight: 600; margin-bottom: .4rem; }
        input[type="text"] {
            width: 100%; font-size: 1.5rem; letter-spacing: .5rem; text-align: center;
            padding: .7rem .5rem; border: 1px solid var(--border); border-radius: 10px;
            background: transparent; color: var(--fg); font-family: inherit;
        }
        input[type="text"]:focus-visible { outline: 3px solid var(--primary); outline-offset: 1px; }
        button {
            width: 100%; margin-top: 1rem; padding: .75rem 1rem; font-size: 1rem; font-weight: 600;
            border: 0; border-radius: 10px; background: var(--primary); color: var(--primary-fg);
            cursor: pointer; font-family: inherit;
        }
        button:hover { filter: brightness(1.05); }
        button:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        .link-btn {
            background: none; color: var(--primary); text-decoration: underline; padding: .5rem;
            width: auto; margin: 0; font-size: .95rem;
        }
        .row { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; gap: .5rem; }
        .error { color: var(--error); font-size: .9rem; margin: .5rem 0 0; }
        .status { color: var(--muted); font-size: .9rem; margin: .75rem 0 0; text-align: center; }
        form.inline { display: inline; }
    </style>
</head>
<body>
    <main>
        <div class="icon" aria-hidden="true">🔐</div>
        <h1>אימות דו-שלבי</h1>
        <p class="lead">
            שלחנו קוד חד-פעמי
            @if ($channel === \App\Enums\TwoFactorChannel::Whatsapp)
                בוואטסאפ
            @else
                למייל
            @endif
            @if ($destination) אל <span class="dest">{{ $destination }}</span>@endif.
            הזינו אותו כאן כדי להמשיך.
        </p>

        <form method="POST" action="{{ route('two-factor.verify') }}">
            @csrf
            <label for="code">קוד האימות</label>
            <input
                type="text"
                id="code"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]*"
                autofocus
                required
                aria-describedby="@error('code') code-error @enderror code-help"
                @error('code') aria-invalid="true" @enderror
            >
            @error('code')
                <p class="error" id="code-error" role="alert">{{ $message }}</p>
            @enderror
            <p class="status" id="code-help">הקוד תקף לזמן מוגבל. לא קיבלתם? אפשר לשלוח שוב.</p>

            <button type="submit">אישור והמשך</button>
        </form>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        <div class="row">
            <form method="POST" action="{{ route('two-factor.resend') }}" class="inline">
                @csrf
                <button type="submit" class="link-btn">שליחת קוד חדש</button>
            </form>
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}" class="inline">
                @csrf
                <button type="submit" class="link-btn">התנתקות</button>
            </form>
        </div>
    </main>
</body>
</html>
