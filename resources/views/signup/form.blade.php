<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>טופס פתיחת כרטיס לקוח — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #2b2b30; --card: #34343a; --fg: #f4f5f7; --muted: #b4bac4;
            --border: #4a4a52; --field: #e9e4dd; --field-fg: #16181d;
            --brand: #ec4899; --brand-fg: #ffffff; --error: #ff8a8a;
            --step-on: #ec4899; --step-off: #6b6b73;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 44rem;
            border-radius: 16px; padding: clamp(1.25rem, 4vw, 2.5rem);
            box-shadow: 0 8px 32px rgb(0 0 0 / .3);
        }
        .logo-wrap { display: flex; justify-content: center; margin-bottom: 1rem; }
        .logo {
            width: 4.5rem; height: 4.5rem; border-radius: .75rem; display: grid; place-items: center;
            background: #f4efe8; box-shadow: 0 2px 10px rgb(0 0 0 / .25);
            font-weight: 800; font-size: 2rem;
            background-image: linear-gradient(135deg, #7c3aed, #ec4899, #f59e0b);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        h1 { font-size: clamp(1.5rem, 5vw, 2rem); margin: 0; text-align: center; }
        p.lead { color: var(--muted); margin: .4rem 0 .1rem; text-align: center; }
        p.lead.small { font-size: .95rem; margin-top: 0; }

        /* Stepper */
        .steps { display: flex; justify-content: space-between; gap: .5rem; margin: 1.75rem 0 1.5rem; }
        .steps .step { flex: 1; text-align: center; position: relative; }
        .steps .step .bar { height: 2px; background: var(--step-off); margin-bottom: .5rem; }
        .steps .step.active .bar, .steps .step.done .bar { background: var(--step-on); }
        .steps .step .num { font-weight: 700; color: var(--step-off); }
        .steps .step.active .num, .steps .step.done .num { color: var(--step-on); }
        .steps .step .label { font-size: .8rem; color: var(--muted); }

        fieldset.panel { border: 0; padding: 0; margin: 0; }
        fieldset.panel[hidden] { display: none; }

        h2 { font-size: 1.05rem; margin: 1.25rem 0 .75rem; }
        .field { margin-bottom: 1.15rem; }
        label { display: block; font-weight: 600; margin-bottom: .35rem; }
        .req { color: var(--error); }
        input[type=text], input[type=email], input[type=tel], select {
            width: 100%; padding: .7rem .8rem; font: inherit;
            background: var(--field); color: var(--field-fg);
            border: 1px solid var(--border); border-radius: 8px;
        }
        input::placeholder { color: #8a8378; }
        input:focus-visible, select:focus-visible, button:focus-visible, canvas:focus-visible {
            outline: 3px solid var(--brand); outline-offset: 2px;
        }
        .error { color: var(--error); font-size: .9rem; margin-top: .35rem; }
        [aria-invalid="true"] { border-color: var(--error); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 32rem) { .row { grid-template-columns: 1fr; } }

        .instructions {
            background: #26262b; border: 1px solid var(--border); border-radius: 10px;
            padding: 1rem 1.1rem; margin-bottom: 1.15rem; white-space: pre-line;
        }
        .instructions[hidden] { display: none; }
        .instructions a { color: var(--brand); word-break: break-all; }
        .instructions .head { font-weight: 700; margin-bottom: .35rem; }

        .terms { display: flex; align-items: flex-start; gap: .5rem; font-size: .95rem; color: var(--muted); }
        .terms input { margin-top: .3rem; accent-color: var(--brand); }
        .terms a { color: var(--brand); }

        /* Signature pad */
        .sig-wrap { position: relative; }
        canvas.sig {
            width: 100%; height: 11rem; background: #ffffff; border-radius: 8px;
            border: 1px solid var(--border); touch-action: none; display: block; cursor: crosshair;
        }
        .sig-clear {
            position: absolute; inset-inline-end: .5rem; top: .5rem; width: auto; margin: 0;
            padding: .3rem .6rem; font-size: .85rem; background: #fff; color: var(--field-fg);
            border: 1px solid var(--border); border-radius: 6px; cursor: pointer;
        }

        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
        .actions.center { justify-content: center; }
        button {
            background: var(--brand); color: var(--brand-fg); border: 0; cursor: pointer;
            font: inherit; font-weight: 700; padding: .8rem 1.75rem; border-radius: 10px; font-size: 1.05rem;
        }
        button.ghost { background: var(--field); color: var(--field-fg); }
        @media (prefers-reduced-motion: no-preference) { button { transition: filter .15s; } }
        button:hover { filter: brightness(1.08); }
        .secure { text-align: center; color: var(--muted); font-size: .85rem; margin-top: 1rem; }
        /* Honeypot: hidden without extending the page's scroll width (no off-screen
           positioning, which would create a horizontal scrollbar). */
        .hp { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
    </style>
</head>
<body>
    <main>
        <div class="logo-wrap">
            @if ($logo = \App\Support\Branding::logoUrl())
                <img src="{{ $logo }}" alt="לוגו" style="max-height:4.5rem;max-width:70%;border-radius:.6rem;">
            @else
                <span class="logo">M</span>
            @endif
        </div>
        <h1>טופס פתיחת כרטיס לקוח</h1>
        <p class="lead">אנו מודים לך על שבחרת בנו ומתחייבים לשירות 1:1 אמין מקצועי וזמין</p>
        <p class="lead small">מילוי הפרטים בטופס זה יחסוך חתימת מסמך ידני. שדות עם <span class="req">*</span> הם חובה.</p>

        @if ($errors->any())
            <div class="error" role="alert" style="margin:.75rem 0">יש לתקן את השדות המסומנים.</div>
        @endif

        <div class="steps" aria-hidden="true">
            <div class="step" data-step-tab="1"><div class="bar"></div><span class="num">1</span><div class="label">פרטי המזמין</div></div>
            <div class="step" data-step-tab="2"><div class="bar"></div><span class="num">2</span><div class="label">פרטי תשלום / פקדון</div></div>
            <div class="step" data-step-tab="3"><div class="bar"></div><span class="num">3</span><div class="label">אישור וסיום</div></div>
        </div>

        <form method="POST" action="{{ route('signup.store') }}" novalidate id="signup-form">
            @csrf

            {{-- ── Step 1: המזמין ── --}}
            <fieldset class="panel" data-step="1">
                <div class="field">
                    <label for="name">שם העסק <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="organization"
                        placeholder="השם שיופיע בחשבוניות"
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
                            <option value="nonprofit" @selected(old('business_type') === 'nonprofit')>עמותה (ע.ר.)</option>
                        </select>
                        @error('business_type') <p class="error" id="bt-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="business_number">ח.פ / ע.מ / ע.ר</label>
                        <input type="text" id="business_number" name="business_number" value="{{ old('business_number') }}"
                            inputmode="numeric" autocomplete="off" placeholder="9 ספרות"
                            data-validate="business_number"
                            @error('business_number') aria-invalid="true" aria-describedby="bn-error" @enderror>
                        <p class="error" id="bn-error" @if (! $errors->has('business_number')) hidden @endif>{{ $errors->first('business_number') ?: 'ח.פ / מספר עוסק חייב להיות 9 ספרות.' }}</p>
                    </div>
                </div>

                <div class="row">
                    <div class="field">
                        <label for="contact_name">איש קשר <span class="req" aria-hidden="true">*</span></label>
                        <input type="text" id="contact_name" name="contact_name" value="{{ old('contact_name') }}" required autocomplete="name"
                            placeholder="המוסמך לייצג את המזמין"
                            @error('contact_name') aria-invalid="true" aria-describedby="contact-error" @enderror>
                        @error('contact_name') <p class="error" id="contact-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="phone">טלפון <span class="req" aria-hidden="true">*</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel" inputmode="tel"
                            placeholder="עדיף נייד זמין (למשל 0501234567)"
                            data-validate="phone"
                            @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                        <p class="error" id="phone-error" @if (! $errors->has('phone')) hidden @endif>{{ $errors->first('phone') ?: 'מספר הטלפון אינו תקין.' }}</p>
                    </div>
                </div>

                <div class="field">
                    <label for="email">אימייל <span class="req" aria-hidden="true">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                        placeholder="אימייל אליו יישלחו מסמכים וחשבוניות"
                        data-validate="email"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    <p class="error" id="email-error" @if (! $errors->has('email')) hidden @endif>{{ $errors->first('email') ?: 'כתובת המייל אינה תקינה.' }}</p>
                </div>

                <div class="field">
                    <label for="domain">כתובת האתר (אופציונלי)</label>
                    <input type="text" id="domain" name="domain" value="{{ old('domain') }}" placeholder="example.co.il" autocomplete="url">
                </div>

                <div class="actions">
                    <button type="button" data-next="2">לשלב הבא ←</button>
                </div>
            </fieldset>

            {{-- ── Step 2: פרטי תשלום ── --}}
            <fieldset class="panel" data-step="2" hidden>
                <h2>איך יבוצע התשלום?</h2>
                <div class="field">
                    <label for="payment_method">אמצעי תשלום <span class="req" aria-hidden="true">*</span></label>
                    <select id="payment_method" name="payment_method" required
                        @error('payment_method') aria-invalid="true" aria-describedby="pm-error" @enderror>
                        <option value="credit_card" @selected(old('payment_method', 'credit_card') === 'credit_card')>בכרטיס אשראי — ייעשה שימוש בכרטיס שפרטיו הוזנו</option>
                        <option value="standing_order" @selected(old('payment_method') === 'standing_order')>הוראת קבע בנקאית</option>
                        <option value="checks" @selected(old('payment_method') === 'checks')>בצ׳קים (מקדמה / תשלום מראש)</option>
                        <option value="bank_transfer" @selected(old('payment_method') === 'bank_transfer')>העברה בנקאית (מקדמה / תשלום מראש)</option>
                    </select>
                    @error('payment_method') <p class="error" id="pm-error">{{ $message }}</p> @enderror
                </div>

                <div class="instructions" data-method="credit_card">
                    <div class="head">כרטיס אשראי</div>
                    לאחר סיום הטופס תועברו להזנת פרטי הכרטיס בעמוד מאובטח של חברת הסליקה (קארדקום). איננו רואים ואיננו שומרים את מספר הכרטיס.
                </div>
                <div class="instructions" data-method="standing_order" hidden>
                    <div class="head">הוראת קבע בנקאית</div>
                    {{-- Escape first (e()), THEN wrap any http(s) URL in a link, so
                         the Kesher authorisation link is clickable without ever
                         emitting unescaped user/settings input. --}}
                    {!! preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', e($instructions['standing_order'] ?? '')) !!}
                </div>
                <div class="instructions" data-method="checks" hidden>
                    <div class="head">צ׳קים (מקדמה / תשלום מראש)</div>
                    {{ $instructions['checks'] ?? '' }}
                </div>
                <div class="instructions" data-method="bank_transfer" hidden>
                    <div class="head">העברה בנקאית (מקדמה / תשלום מראש)</div>
                    {{ $instructions['bank_transfer'] ?? '' }}
                </div>

                @if (filled($taxNotice ?? null))
                    <div class="instructions" style="border-style:dashed;">
                        <div class="head">אישורי ניהול ספרים / ניכוי מס במקור</div>
                        {{-- Escape first, then linkify the taxes.gov.il URL. --}}
                        {!! preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', e($taxNotice)) !!}
                    </div>
                @endif

                <div class="actions">
                    <button type="button" class="ghost" data-prev="1">חזור</button>
                    <button type="button" data-next="3">לשלב הבא ←</button>
                </div>
            </fieldset>

            {{-- ── Step 3: אישור וסיום ── --}}
            <fieldset class="panel" data-step="3" hidden>
                <h2>אישור וחתימה</h2>
                <div class="field terms">
                    <input type="checkbox" id="terms" name="terms" value="1" required @checked(old('terms'))>
                    <label for="terms" style="font-weight:400;margin:0">קראתי את <a href="#" target="_blank" rel="noopener">תנאי השירות והתקנון</a> ואני מאשר/ת אותם, וכי החיוב יתבצע באופן מתחדש בהתאם למנוי שיוגדר.</label>
                </div>
                @error('terms') <p class="error">{{ $message }}</p> @enderror

                <div class="field">
                    <label for="signature-pad">חתימה <span class="req" aria-hidden="true">*</span></label>
                    <div class="sig-wrap">
                        <canvas id="signature-pad" class="sig" tabindex="0" aria-label="תיבת חתימה — חתמו כאן"></canvas>
                        <button type="button" class="sig-clear" id="sig-clear">ניקוי ✕</button>
                    </div>
                    <input type="hidden" name="signature" id="signature-input" value="{{ old('signature') }}">
                    <p class="error" id="sig-error" hidden>יש לחתום בתיבת החתימה.</p>
                    @error('signature') <p class="error">{{ $message }}</p> @enderror
                </div>

                {{-- Honeypot — hidden from users and assistive tech; bots fill it and get rejected. --}}
                <div class="hp" aria-hidden="true">
                    <label for="website">אתר</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="actions">
                    <button type="button" class="ghost" data-prev="2">חזור</button>
                    <button type="submit">אישור וסיום</button>
                </div>
            </fieldset>
        </form>

        <p class="secure">אתר זה מאובטח וכתובת ה-IP של ממלא הטופס מתועדת.</p>
    </main>

    <script>
        (function () {
            var form = document.getElementById('signup-form');
            var panels = Array.prototype.slice.call(form.querySelectorAll('.panel'));
            var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-step-tab]'));

            function show(step) {
                panels.forEach(function (p) { p.hidden = p.getAttribute('data-step') !== String(step); });
                tabs.forEach(function (t) {
                    var n = Number(t.getAttribute('data-step-tab'));
                    t.classList.toggle('active', n === Number(step));
                    t.classList.toggle('done', n < Number(step));
                });
                // The signature canvas is display:none until step 3 opens, so its
                // backing store must be (re)sized to its now-visible dimensions.
                if (Number(step) === 3) { resize(); }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            // Per-field format rules (mirrors the server-side validation).
            var FORMATS = {
                email: { test: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }, error: 'email-error' },
                phone: { test: function (v) { return /^0\d{8,9}$/.test(v.replace(/\D+/g, '')); }, error: 'phone-error' },
                business_number: { test: function (v) { return v === '' || /^\d{9}$/.test(v.replace(/\D+/g, '')); }, error: 'bn-error' },
            };

            function setError(el, errorId, show) {
                var box = errorId ? document.getElementById(errorId) : null;
                if (show) { el.setAttribute('aria-invalid', 'true'); if (box) box.hidden = false; }
                else { el.removeAttribute('aria-invalid'); if (box) box.hidden = true; }
            }

            // Check one field's format (if it has a data-validate rule). Empty,
            // non-required fields pass; required-empty is handled by stepValid.
            function formatValid(el) {
                var rule = FORMATS[el.getAttribute('data-validate')];
                if (!rule) { return true; }
                var v = String(el.value).trim();
                if (v === '' && !el.required) { setError(el, rule.error, false); return true; }
                var ok = rule.test(v);
                setError(el, rule.error, !ok);
                return ok;
            }

            // Validate required + format for every field inside a step.
            function stepValid(step) {
                var panel = form.querySelector('.panel[data-step="' + step + '"]');
                var ok = true;
                panel.querySelectorAll('input[required], select[required]').forEach(function (el) {
                    if (el.type === 'checkbox' ? !el.checked : !String(el.value).trim()) {
                        el.setAttribute('aria-invalid', 'true');
                        ok = false;
                    } else {
                        el.removeAttribute('aria-invalid');
                    }
                });
                panel.querySelectorAll('[data-validate]').forEach(function (el) {
                    if (!formatValid(el)) { ok = false; }
                });
                if (!ok) { var f = panel.querySelector('[aria-invalid="true"]'); if (f) f.focus(); }
                return ok;
            }

            // Live feedback: re-check a field's format when the user leaves it.
            form.querySelectorAll('[data-validate]').forEach(function (el) {
                el.addEventListener('blur', function () { formatValid(el); });
            });

            document.querySelectorAll('[data-next]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var current = btn.closest('.panel').getAttribute('data-step');
                    if (stepValid(current)) { show(btn.getAttribute('data-next')); }
                });
            });
            document.querySelectorAll('[data-prev]').forEach(function (btn) {
                btn.addEventListener('click', function () { show(btn.getAttribute('data-prev')); });
            });

            // Payment-method instructions toggle.
            var pm = document.getElementById('payment_method');
            function syncInstructions() {
                document.querySelectorAll('.instructions[data-method]').forEach(function (el) {
                    el.hidden = el.getAttribute('data-method') !== pm.value;
                });
            }
            pm.addEventListener('change', syncInstructions);
            syncInstructions();

            // ── Signature pad (vanilla canvas, no library) ──
            var canvas = document.getElementById('signature-pad');
            var input = document.getElementById('signature-input');
            var sigError = document.getElementById('sig-error');
            var ctx = canvas.getContext('2d');
            var drawing = false, dirty = false;

            function resize() {
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                var rect = canvas.getBoundingClientRect();
                var data = dirty ? canvas.toDataURL() : null;
                canvas.width = rect.width * ratio;
                canvas.height = rect.height * ratio;
                ctx.scale(ratio, ratio);
                ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#16181d';
                if (data) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); }; img.src = data; }
            }
            window.addEventListener('resize', resize);
            resize();

            function pos(e) {
                var rect = canvas.getBoundingClientRect();
                var p = e.touches ? e.touches[0] : e;
                return { x: p.clientX - rect.left, y: p.clientY - rect.top };
            }
            function start(e) { drawing = true; dirty = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
            function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
            function end() { drawing = false; }

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', end);

            document.getElementById('sig-clear').addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height); dirty = false; input.value = '';
            });

            form.addEventListener('submit', function (e) {
                // Re-check every step's required fields, then the signature.
                for (var s = 1; s <= 3; s++) {
                    if (!stepValid(s)) { e.preventDefault(); show(s); return; }
                }
                if (!dirty) { e.preventDefault(); show(3); sigError.hidden = false; canvas.focus(); return; }
                input.value = canvas.toDataURL('image/png');
            });

            // On a server validation error, open the first step that has one.
            @if ($errors->any())
                (function () {
                    for (var s = 1; s <= 3; s++) {
                        var panel = form.querySelector('.panel[data-step="' + s + '"]');
                        if (panel.querySelector('.error')) { show(s); return; }
                    }
                    show(1);
                })();
            @else
                show(1);
            @endif
        })();
    </script>
</body>
</html>
