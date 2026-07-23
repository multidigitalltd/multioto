<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>דירוג שירות — פנייה #{{ $ticket->id }}</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e; --border: #c2c8d0;
            --accent: #2563eb; --accent-fg: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba; --border: #37415a; --accent: #3b82f6; }
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
        h1 { font-size: 1.3rem; margin: .25rem 0 .5rem; }
        p { color: var(--muted); margin: 0 0 1rem; line-height: 1.6; }
        fieldset { border: 0; padding: 0; margin: 0 0 1rem; }
        legend { font-weight: 600; margin-bottom: .5rem; }
        .scale { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; }
        .scale input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .scale label {
            cursor: pointer; border: 2px solid var(--border); border-radius: 12px;
            width: 3rem; height: 3.25rem; display: flex; flex-direction: column; align-items: center;
            justify-content: center; font-size: 1.4rem; line-height: 1; transition: border-color .15s, background .15s;
        }
        .scale label small { font-size: .7rem; color: var(--muted); margin-top: .15rem; }
        .scale input:checked + label { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 12%, transparent); }
        .scale input:focus-visible + label { outline: 3px solid var(--accent); outline-offset: 2px; }
        textarea {
            width: 100%; min-height: 4.5rem; padding: .6rem .75rem; border-radius: 10px;
            border: 1px solid var(--border); background: var(--bg); color: var(--fg); font: inherit; resize: vertical;
        }
        label.field { display: block; text-align: start; font-weight: 600; margin-bottom: .35rem; }
        button {
            margin-top: 1rem; width: 100%; padding: .75rem 1rem; border: 0; border-radius: 10px;
            background: var(--accent); color: var(--accent-fg); font: inherit; font-weight: 600; cursor: pointer;
        }
        button:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        .error { color: #dc2626; font-size: .85rem; margin: -.5rem 0 .75rem; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
    <main>
        <div style="font-size:2.25rem" aria-hidden="true">💬</div>
        <h1>איך היה השירות שלנו?</h1>
        <p>הפנייה שלך (#{{ $ticket->id }}) טופלה. נשמח לדעת עד כמה היית מרוצה — זה עוזר לנו להשתפר.</p>

        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ $action }}" novalidate>
            @csrf
            <fieldset>
                <legend>דירוג (1 = לא מרוצה, 5 = מרוצה מאוד)</legend>
                <div class="scale">
                    @foreach ([1 => '😠', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😀'] as $value => $face)
                        <input type="radio" name="rating" id="rating-{{ $value }}" value="{{ $value }}"
                               @checked((int) old('rating', $ticket->csat_rating) === $value) required>
                        <label for="rating-{{ $value }}" aria-label="{{ $value }} מתוך 5">
                            <span aria-hidden="true">{{ $face }}</span><small>{{ $value }}</small>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <label class="field" for="comment">הערה (לא חובה)</label>
            <textarea id="comment" name="comment" maxlength="1000" placeholder="מה היה טוב, ומה אפשר לשפר?">{{ old('comment', $ticket->csat_comment) }}</textarea>

            <button type="submit">שליחת הדירוג</button>
        </form>
    </main>
</body>
</html>
