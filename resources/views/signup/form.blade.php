<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>הצטרפות למולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e;
            --border: #c2c8d0; --brand: #4f46e5; --brand-fg: #ffffff;
            --error: #b3261e; --sel-bg: #eef0ff; --sel-border: #4f46e5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1729; --card: #1c2434; --fg: #f4f5f7; --muted: #a3adba;
                --border: #37415a; --brand: #818cf8; --brand-fg: #0b1220;
                --error: #ff6b6b; --sel-bg: #26305a; --sel-border: #818cf8;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 40rem;
            border-radius: 16px; padding: clamp(1.25rem, 4vw, 2.5rem);
            box-shadow: 0 4px 24px rgb(0 0 0 / .1);
        }
        .head { display: flex; align-items: center; gap: .6rem; margin-bottom: .25rem; }
        .logo { width: 2.2rem; height: 2.2rem; border-radius: .6rem; display: grid; place-items: center;
            background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; font-weight: 800; }
        h1 { font-size: 1.5rem; margin: 0; }
        p.lead { color: var(--muted); margin: .25rem 0 1.75rem; }
        h2 { font-size: 1.05rem; margin: 1.5rem 0 .75rem; }
        .field { margin-bottom: 1.15rem; }
        label { display: block; font-weight: 600; margin-bottom: .35rem; }
        .req { color: var(--error); }
        input[type=text], input[type=email], input[type=tel], select {
            width: 100%; padding: .7rem .8rem; font: inherit; color: inherit;
            background: transparent; border: 1px solid var(--border); border-radius: 8px;
        }
        input:focus-visible, select:focus-visible, button:focus-visible, .plan:focus-within {
            outline: 3px solid var(--brand); outline-offset: 2px;
        }
        .error { color: var(--error); font-size: .9rem; margin-top: .35rem; }
        [aria-invalid="true"] { border-color: var(--error); }
        .plans { display: grid; gap: .75rem; }
        .plan {
            position: relative; border: 2px solid var(--border); border-radius: 12px;
            padding: 1rem 1.1rem; cursor: pointer; display: flex; align-items: flex-start; gap: .75rem;
        }
        .plan:hover { border-color: var(--sel-border); }
        .plan input { margin-top: .25rem; accent-color: var(--brand); width: 1.15rem; height: 1.15rem; flex-shrink: 0; }
        .plan:has(input:checked) { border-color: var(--sel-border); background: var(--sel-bg); }
        .plan .info { flex: 1; }
        .plan .name { font-weight: 700; font-size: 1.05rem; }
        .plan .desc { color: var(--muted); font-size: .9rem; }
        .plan .price { font-weight: 800; font-size: 1.1rem; white-space: nowrap; }
        .plan .price small { font-weight: 500; color: var(--muted); font-size: .8rem; display: block; text-align: left; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 32rem) { .row { grid-template-columns: 1fr; } }
        .terms { display: flex; align-items: flex-start; gap: .5rem; font-size: .95rem; color: var(--muted); }
        .terms input { margin-top: .3rem; accent-color: var(--brand); }
        button {
            margin-top: 1.5rem; background: var(--brand); color: var(--brand-fg); border: 0; cursor: pointer;
            font: inherit; font-weight: 700; padding: .9rem 1.5rem; border-radius: 10px; width: 100%; font-size: 1.05rem;
        }
        @media (prefers-reduced-motion: no-preference) { button { transition: filter .15s; } }
        button:hover { filter: brightness(1.08); }
        .secure { text-align: center; color: var(--muted); font-size: .85rem; margin-top: 1rem; }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .empty { color: var(--muted); padding: 1rem; border: 1px dashed var(--border); border-radius: 10px; }
    </style>
</head>
<body>
    <main>
        <div class="head">
            <span class="logo">M</span>
            <h1>הצטרפות למולטי דיגיטל</h1>
        </div>
        <p class="lead">כמה פרטים ובחירת מסלול, ומעבירים אתכם לעמוד תשלום מאובטח. שדות עם <span class="req">*</span> הם חובה.</p>

        @if ($errors->any())
            <div class="error" role="alert" style="margin-bottom:1rem">יש לתקן את השדות המסומנים למטה.</div>
        @endif

        <form method="POST" action="{{ route('signup.store') }}" novalidate>
            @csrf

            <h2>בחירת מסלול <span class="req" aria-hidden="true">*</span></h2>
            @if ($plans->isEmpty())
                <p class="empty">אין כרגע מסלולים זמינים. אנא פנו אלינו ונשמח לעזור.</p>
            @else
                <div class="plans" role="radiogroup" aria-label="בחירת מסלול">
                    @foreach ($plans as $plan)
                        <label class="plan">
                            <input type="radio" name="plan_id" value="{{ $plan->id }}"
                                @checked(old('plan_id') == $plan->id) required>
                            <span class="info">
                                <span class="name">{{ $plan->name }}</span>
                                @if ($plan->description)
                                    <span class="desc">{{ $plan->description }}</span>
                                @endif
                            </span>
                            <span class="price">
                                ₪{{ number_format($plan->price_agorot / 100) }}
                                <small>{{ $plan->vat_applies ? 'לחודש + מע״מ' : 'לחודש' }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('plan_id') <p class="error">{{ $message }}</p> @enderror
            @endif

            <h2>פרטי העסק</h2>
            <div class="field">
                <label for="name">שם מלא / שם העסק <span class="req" aria-hidden="true">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="organization"
                    @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <p class="error" id="name-error">{{ $message }}</p> @enderror
            </div>

            <div class="row">
                <div class="field">
                    <label for="business_type">סוג עסק <span class="req" aria-hidden="true">*</span></label>
                    <select id="business_type" name="business_type" required
                        @error('business_type') aria-invalid="true" aria-describedby="bt-error" @enderror>
                        <option value="" disabled @selected(! old('business_type'))>בחרו…</option>
                        <option value="licensed_dealer" @selected(old('business_type') === 'licensed_dealer')>עוסק מורשה</option>
                        <option value="exempt_dealer" @selected(old('business_type') === 'exempt_dealer')>עוסק פטור</option>
                        <option value="company" @selected(old('business_type') === 'company')>חברה בע״מ</option>
                    </select>
                    @error('business_type') <p class="error" id="bt-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="business_number">ח.פ / מספר עוסק</label>
                    <input type="text" id="business_number" name="business_number" value="{{ old('business_number') }}"
                        inputmode="numeric" autocomplete="off">
                </div>
            </div>

            <h2>פרטי התקשרות</h2>
            <div class="row">
                <div class="field">
                    <label for="email">אימייל <span class="req" aria-hidden="true">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email') <p class="error" id="email-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="phone">טלפון <span class="req" aria-hidden="true">*</span></label>
                    <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel" inputmode="tel"
                        @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                    @error('phone') <p class="error" id="phone-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field">
                <label for="domain">כתובת האתר (אופציונלי)</label>
                <input type="text" id="domain" name="domain" value="{{ old('domain') }}" placeholder="example.co.il" autocomplete="url">
            </div>

            <div class="field terms">
                <input type="checkbox" id="terms" name="terms" value="1" required @checked(old('terms'))>
                <label for="terms" style="font-weight:400;margin:0">אני מאשר/ת את תנאי השירות ומדיניות הפרטיות, וכי החיוב יתבצע באופן חודשי מתחדש.</label>
            </div>
            @error('terms') <p class="error">{{ $message }}</p> @enderror

            {{-- Honeypot — hidden from users and assistive tech; bots fill it and get rejected. --}}
            <div class="hp" aria-hidden="true">
                <label for="website">אתר</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" @disabled($plans->isEmpty())>המשך לתשלום מאובטח ←</button>
            <p class="secure">🔒 פרטי האשראי מוזנים בעמוד המאובטח של חברת הסליקה. איננו רואים ואיננו שומרים את מספר הכרטיס.</p>
        </form>
    </main>
</body>
</html>
