<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>יצירת קשר ותמיכה — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e;
            --border: #c2c8d0; --brand: #1c5fd6; --brand-fg: #ffffff;
            --error: #b3261e; --ok-bg: #dff3e4; --ok-fg: #14532d;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #16181d; --card: #23262d; --fg: #f4f5f7; --muted: #a3adba;
                --border: #3a3f48; --brand: #6ea8fe; --brand-fg: #0b1220;
                --error: #ff6b6b; --ok-bg: #14311f; --ok-fg: #b9f0cd;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 34rem;
            border-radius: 14px; padding: clamp(1.25rem, 4vw, 2.5rem);
            box-shadow: 0 2px 16px rgb(0 0 0 / .08);
        }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        p.lead { color: var(--muted); margin: 0 0 1.5rem; }
        .field { margin-bottom: 1.15rem; }
        label { display: block; font-weight: 600; margin-bottom: .35rem; }
        .req { color: var(--error); }
        input, textarea {
            width: 100%; padding: .7rem .8rem; font: inherit; color: inherit;
            background: transparent; border: 1px solid var(--border); border-radius: 8px;
        }
        textarea { resize: vertical; min-height: 7rem; }
        input:focus-visible, textarea:focus-visible, button:focus-visible {
            outline: 3px solid var(--brand); outline-offset: 2px;
        }
        .error { color: var(--error); font-size: .9rem; margin-top: .35rem; }
        [aria-invalid="true"] { border-color: var(--error); }
        button {
            background: var(--brand); color: var(--brand-fg); border: 0; cursor: pointer;
            font: inherit; font-weight: 700; padding: .8rem 1.5rem; border-radius: 8px; width: 100%;
        }
        @media (prefers-reduced-motion: no-preference) { button { transition: filter .15s; } }
        button:hover { filter: brightness(1.08); }
        .status {
            background: var(--ok-bg); color: var(--ok-fg); border-radius: 8px;
            padding: .85rem 1rem; margin-bottom: 1.5rem; font-weight: 600;
        }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    </style>
</head>
<body>
    <main>
        <h1>יצירת קשר ותמיכה</h1>
        <p class="lead">שלחו לנו פנייה ונחזור אליכם בהקדם. שדות המסומנים ב-<span class="req">*</span> הם חובה.</p>

        @if (session('status'))
            <div class="status" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('support.form.store') }}" novalidate>
            @csrf

            <div class="field">
                <label for="name">שם <span class="req" aria-hidden="true">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    autocomplete="name" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <p class="error" id="name-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="email">מייל <span class="req" aria-hidden="true">*</span></label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                    autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="error" id="email-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="phone">טלפון</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                    autocomplete="tel" inputmode="tel" @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                @error('phone') <p class="error" id="phone-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="subject">נושא <span class="req" aria-hidden="true">*</span></label>
                <input type="text" id="subject" name="subject" value="{{ old('subject') }}" required
                    @error('subject') aria-invalid="true" aria-describedby="subject-error" @enderror>
                @error('subject') <p class="error" id="subject-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="message">תוכן הפנייה <span class="req" aria-hidden="true">*</span></label>
                <textarea id="message" name="message" required
                    @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                @error('message') <p class="error" id="message-error">{{ $message }}</p> @enderror
            </div>

            {{-- Honeypot — hidden from users and assistive tech; bots fill it and get rejected. --}}
            <div class="hp" aria-hidden="true">
                <label for="website">אתר</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit">שליחת פנייה</button>
        </form>
    </main>
</body>
</html>
