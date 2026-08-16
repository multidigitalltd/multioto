<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->name }} — רכישת רישיון</title>
    <meta name="description" content="{{ Str::limit(strip_tags((string) $product->description), 150) }}">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #2b2b30; --card: #34343a; --fg: #f4f5f7; --muted: #b4bac4;
            --border: #4a4a52; --field: #e9e4dd; --field-fg: #16181d;
            --brand: #ec4899; --brand-fg: #ffffff; --error: #ff8a8a; --ok: #4ade80;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main { background: var(--card); width: 100%; max-width: 40rem; border-radius: 16px;
               padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: 0 8px 32px rgb(0 0 0 / .3); }
        h1 { font-size: clamp(1.5rem, 5vw, 2rem); margin: 0 0 .25rem; }
        p.lead { color: var(--muted); margin: 0 0 1.25rem; }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        .req { color: var(--brand); }
        input[type=text], input[type=email], input[type=tel] {
            width: 100%; padding: .7rem .8rem; border-radius: 10px; border: 1px solid var(--border);
            background: var(--field); color: var(--field-fg); font: inherit;
        }
        input:focus-visible, button:focus-visible, a:focus-visible {
            outline: 3px solid var(--brand); outline-offset: 2px;
        }
        .hint { color: var(--muted); font-size: .9rem; margin-top: .3rem; }
        .check { display: flex; gap: .6rem; align-items: flex-start; margin: 1.25rem 0; }
        .check input { margin-top: .35rem; width: 1.1rem; height: 1.1rem; }
        button {
            width: 100%; padding: .9rem 1rem; border: 0; border-radius: 10px; font: inherit;
            font-weight: 700; background: var(--brand); color: var(--brand-fg); cursor: pointer;
        }
        button:hover { filter: brightness(1.08); }
        .errors { background: rgb(255 138 138 / .12); border: 1px solid var(--error);
                  border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .errors ul { margin: .25rem 0 0; padding-inline-start: 1.1rem; }
        .error { color: var(--error); font-size: .9rem; margin-top: .3rem; }
        .foot { color: var(--muted); font-size: .9rem; margin-top: 1.25rem; text-align: center; }
        fieldset.plans { border: 0; margin: 0 0 .5rem; padding: 0; }
        fieldset.plans legend { font-weight: 700; margin-bottom: .5rem; padding: 0; }
        .plan { display: flex; gap: .7rem; align-items: flex-start; border: 1px solid var(--border);
                border-radius: 12px; padding: .9rem 1rem; margin-bottom: .6rem; cursor: pointer;
                font-weight: 400; }
        .plan:hover { border-color: var(--brand); }
        .plan input { margin-top: .35rem; width: 1.15rem; height: 1.15rem; flex: none; }
        .plan:has(input:checked) { border-color: var(--brand); background: rgb(236 72 153 / .08); }
        .plan .body { display: flex; flex-direction: column; gap: .15rem; }
        .plan .title { font-weight: 700; }
        .plan .price { font-size: 1.25rem; font-weight: 800; }
        .plan .meta { color: var(--muted); font-size: .9rem; }
        .allplans { color: var(--muted); font-size: .92rem; margin: 0 0 1.25rem; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $product->name }}</h1>

    @if (filled($product->description))
        <p class="lead">{{ $product->description }}</p>
    @endif

    <fieldset class="plans">
        <legend>בחרו מסלול</legend>
        @foreach ($plans as $plan)
            <label class="plan" for="plan-{{ $plan->id }}">
                <input type="radio" name="plan" id="plan-{{ $plan->id }}" value="{{ $plan->id }}"
                       form="buy" required @checked(old('plan', $plans->first()->id) == $plan->id)>
                <span class="body">
                    <span class="title">{{ $plan->name }}</span>
                    <span class="price">{{ $plan->priceLabel() }}</span>
                    {{-- The two facts that decide whether the purchase becomes a
                         dispute: how many sites it covers, and what happens to
                         updates afterwards. Both on the button, not in the small
                         print underneath. --}}
                    <span class="meta">{{ $plan->sitesLabel() }} · {{ $plan->updatesLabel() }}</span>
                    @if (filled($plan->description))
                        <span class="meta">{{ $plan->description }}</span>
                    @endif
                    @if ($plan->renews())
                        <span class="meta">מתחדש אוטומטית. אפשר לבטל בכל עת ולא תחויבו לתקופה הבאה.</span>
                    @endif
                </span>
            </label>
        @endforeach
    </fieldset>

    <p class="allplans">בכל המסלולים: <strong>התוסף ממשיך לעבוד באתר תמיד</strong> — גם אם העדכונים נעצרים. המחירים כוללים מע״מ.</p>

    @if ($errors->any())
        <div class="errors" role="alert">
            <strong>לא ניתן להמשיך:</strong>
            <ul>
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form id="buy" method="POST" action="{{ route('store.buy', ['slug' => $product->slug]) }}" novalidate>
        @csrf

        <label for="name">שם מלא <span class="req" aria-hidden="true">*</span></label>
        <input id="name" name="name" type="text" required autocomplete="name"
               value="{{ old('name') }}"
               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror

        <label for="email">אימייל <span class="req" aria-hidden="true">*</span></label>
        <input id="email" name="email" type="email" required autocomplete="email" inputmode="email"
               value="{{ old('email') }}"
               aria-describedby="email-hint @error('email') email-error @enderror">
        <p class="hint" id="email-hint">לכתובת הזו יישלח מפתח הרישיון. ודאו שהיא נכונה.</p>
        @error('email')<p class="error" id="email-error">{{ $message }}</p>@enderror

        <label for="phone">טלפון</label>
        <input id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel"
               value="{{ old('phone') }}"
               @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
        @error('phone')<p class="error" id="phone-error">{{ $message }}</p>@enderror

        <div class="check">
            <input id="terms" name="terms" type="checkbox" value="1" required
                   @checked(old('terms'))
                   @error('terms') aria-invalid="true" aria-describedby="terms-error" @enderror>
            <label for="terms" style="margin:0;font-weight:400">
                קראתי ואני מאשר/ת את תנאי השימוש ומדיניות הפרטיות, ובמסלול מתחדש — גם את החידוש האוטומטי.
            </label>
        </div>
        @error('terms')<p class="error" id="terms-error">{{ $message }}</p>@enderror

        <button type="submit">מעבר לתשלום מאובטח</button>
    </form>

    <p class="foot">
        התשלום מתבצע בעמוד מאובטח של חברת הסליקה. פרטי האשראי אינם נשמרים אצלנו.
    </p>
</main>
</body>
</html>
