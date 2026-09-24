@php
    /**
     * עמוד המכירה של סוכן האתר.
     *
     * The form asks for one thing the plugin store never had to: the phone
     * number that will drive the site. It is the product — not a contact detail
     * — so it is asked for with the same weight as the email, and the page says
     * out loud what will happen to it, because a six-digit code arriving from an
     * unknown number is otherwise indistinguishable from a scam.
     */
@endphp
    <!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>סוכן האתר — ניהול האתר מווטסאפ</title>
    <meta name="description" content="שולחים הודעה בוואטסאפ, והאתר מתעדכן. שינוי טקסט, החלפת תמונה, עדכון מחיר — באישור שלכם, בלי להיכנס לוורדפרס.">
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
        main { background: var(--card); width: 100%; max-width: 42rem; border-radius: 16px;
               padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: 0 8px 32px rgb(0 0 0 / .3); }
        h1 { font-size: clamp(1.5rem, 5vw, 2rem); margin: 0 0 .25rem; }
        h2 { font-size: 1.1rem; margin: 1.75rem 0 .5rem; }
        p.lead { color: var(--muted); margin: 0 0 1.25rem; }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        .req { color: var(--brand); }
        input[type=text], input[type=email], input[type=tel], input[type=url] {
            width: 100%; padding: .7rem .8rem; border-radius: 10px; border: 1px solid var(--border);
            background: var(--field); color: var(--field-fg); font: inherit;
        }
        input:focus-visible, button:focus-visible, a:focus-visible {
            outline: 3px solid var(--brand); outline-offset: 2px;
        }
        .hint { color: var(--muted); font-size: .9rem; margin-top: .3rem; }
        ul.does { color: var(--muted); margin: 0 0 1.25rem; padding-inline-start: 1.2rem; }
        ul.does li { margin-bottom: .3rem; }
        .check { display: flex; gap: .6rem; align-items: flex-start; margin: 1.25rem 0; }
        .check input { margin-top: .35rem; width: 1.1rem; height: 1.1rem; flex: none; }
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
        fieldset { border: 0; margin: 0 0 .5rem; padding: 0; }
        fieldset legend { font-weight: 700; margin-bottom: .5rem; padding: 0; }
        .opt { display: flex; gap: .7rem; align-items: flex-start; border: 1px solid var(--border);
               border-radius: 12px; padding: .9rem 1rem; margin-bottom: .6rem; cursor: pointer;
               font-weight: 400; }
        .opt:hover { border-color: var(--brand); }
        .opt input { margin-top: .35rem; width: 1.15rem; height: 1.15rem; flex: none; }
        .opt:has(input:checked) { border-color: var(--brand); background: rgb(236 72 153 / .08); }
        .opt .body { display: flex; flex-direction: column; gap: .15rem; }
        .opt .title { font-weight: 700; }
        .opt .price { font-size: 1.25rem; font-weight: 800; }
        .opt .meta { color: var(--muted); font-size: .9rem; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
<main>
    <h1>סוכן האתר</h1>
    <p class="lead">שולחים הודעה בוואטסאפ — והאתר מתעדכן. בלי להיכנס לוורדפרס, בלי לחכות לאף אחד.</p>

    <ul class="does">
        <li>"תחליף את הטלפון בעמוד צור קשר ל־03-1234567"</li>
        <li>"תעלה את התמונה הזאת לעמוד הבית"</li>
        <li>"תעדכן את המחיר של המוצר לשלוש מאות שקלים"</li>
    </ul>

    {{-- The sentence that decides whether somebody trusts this at all. An agent
         that can edit a business's website has to be one the owner approves
         each change on, and saying so before the price is the difference
         between a product and a risk. --}}
    <p class="lead">
        <strong>כל שינוי מוצג לכם לאישור בצ׳אט לפני שהוא מבוצע</strong>, ולכל שינוי יש ביטול.
        הסוכן אינו נוגע בעיצוב, בקוד או במסד הנתונים.
    </p>

    <h2>המסלול</h2>
    <fieldset>
        <legend class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">בחרו מסלול</legend>
        @foreach ($plans as $plan)
            <label class="opt" for="plan-{{ $plan->id }}">
                <input type="radio" name="plan" id="plan-{{ $plan->id }}" value="{{ $plan->id }}"
                       form="buy" required @checked(old('plan', $plans->first()->id) == $plan->id)>
                <span class="body">
                    <span class="title">{{ $plan->name }}</span>
                    <span class="price">{{ $plan->priceLabel() }}</span>
                    @if (filled($plan->description))
                        <span class="meta">{{ $plan->description }}</span>
                    @endif
                    {{-- The second number's price belongs on the button, not in
                         the small print: a business with a partner or an office
                         manager is deciding right now whether this covers them. --}}
                    @if ($plan->sellsExtraNumbers())
                        <span class="meta">
                            מספר נוסף לאותו אתר:
                            {{ $plan->extra_number_price_agorot > 0
                                ? \App\Support\Money::ils($plan->extraNumberGrossAgorot()).' '.$plan->intervalLabel()
                                : 'ללא תוספת תשלום' }}
                            — אפשר להוסיף בכל שלב מהאזור האישי.
                        </span>
                    @endif
                    <span class="meta">מתחדש אוטומטית. אפשר לבטל בכל עת ולא תחויבו לתקופה הבאה.</span>
                </span>
            </label>
        @endforeach
    </fieldset>

    @if ($errors->any())
        <div class="errors" role="alert">
            <strong>לא ניתן להמשיך:</strong>
            <ul>
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form id="buy" method="POST" action="{{ route('store.agent.buy') }}" novalidate>
        @csrf

        <h2>הפרטים שלכם</h2>

        <label for="name">שם מלא <span class="req" aria-hidden="true">*</span></label>
        <input id="name" name="name" type="text" required autocomplete="name"
               value="{{ old('name') }}"
               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror

        <label for="email">אימייל <span class="req" aria-hidden="true">*</span></label>
        <input id="email" name="email" type="email" required autocomplete="email" inputmode="email"
               value="{{ old('email') }}"
               aria-describedby="email-hint @error('email') email-error @enderror">
        <p class="hint" id="email-hint">לכתובת הזו תישלח החשבונית והוראות ההפעלה.</p>
        @error('email')<p class="error" id="email-error">{{ $message }}</p>@enderror

        <label for="domain">כתובת האתר <span class="req" aria-hidden="true">*</span></label>
        <input id="domain" name="domain" type="text" required dir="ltr" placeholder="example.co.il"
               value="{{ old('domain') }}"
               aria-describedby="domain-hint @error('domain') domain-error @enderror">
        <p class="hint" id="domain-hint">האתר שהסוכן ינהל. אתר וורדפרס.</p>
        @error('domain')<p class="error" id="domain-error">{{ $message }}</p>@enderror

        <h2>המספר שינהל את האתר</h2>

        <label for="phone">מספר וואטסאפ <span class="req" aria-hidden="true">*</span></label>
        <input id="phone" name="phone" type="tel" required autocomplete="tel" inputmode="tel" dir="ltr"
               placeholder="050-1234567"
               value="{{ old('phone') }}"
               aria-describedby="phone-hint @error('phone') phone-error @enderror">
        {{-- Said before they type it, not after: a code from an unfamiliar number
             is otherwise indistinguishable from the scam everybody has been
             warned about, and the ones who are careful are the ones who will not
             answer it. --}}
        <p class="hint" id="phone-hint">
            מיד אחרי התשלום יישלח למספר הזה קוד בן 6 ספרות בוואטסאפ. יש להשיב עליו באותה שיחה —
            עד אז המספר אינו יכול לעשות דבר באתר.
        </p>
        @error('phone')<p class="error" id="phone-error">{{ $message }}</p>@enderror

        <label for="manager_name">שם מנהל האתר</label>
        <input id="manager_name" name="manager_name" type="text" autocomplete="name"
               value="{{ old('manager_name') }}">
        <p class="hint">אופציונלי. אם המספר אינו שלכם — למשל של מנהלת המשרד.</p>

        <h2>ההתקנה</h2>
        <fieldset>
            <legend style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">מי מתקין את התוסף</legend>

            <label class="opt" for="install-self">
                <input type="radio" name="install_mode" id="install-self" value="{{ \App\Models\SiteAgentOrder::INSTALL_SELF }}"
                       required @checked(old('install_mode', \App\Models\SiteAgentOrder::INSTALL_SELF) === \App\Models\SiteAgentOrder::INSTALL_SELF)>
                <span class="body">
                    <span class="title">אני אתקין לבד</span>
                    <span class="meta">מיד אחרי התשלום תקבלו את קובץ התוסף ושלושה קודים להדבקה. כחמש דקות.</span>
                </span>
            </label>

            <label class="opt" for="install-us">
                <input type="radio" name="install_mode" id="install-us" value="{{ \App\Models\SiteAgentOrder::INSTALL_BY_US }}"
                       @checked(old('install_mode') === \App\Models\SiteAgentOrder::INSTALL_BY_US)>
                <span class="body">
                    <span class="title">תתקינו לי</span>
                    {{-- The price of this option is stated here rather than
                         discovered on the next screen: it requires handing us
                         administrator access, and somebody who would rather not
                         should be choosing the other option now. --}}
                    <span class="meta">
                        נתקין עבורכם, ללא תשלום נוסף. בעמוד הבא תתבקשו לתת גישת מנהל לאתר —
                        עדיף קישור התחברות זמני שפג מעצמו. הגישה נמחקת אצלנו בתום ההתקנה.
                    </span>
                </span>
            </label>
        </fieldset>
        @error('install_mode')<p class="error">{{ $message }}</p>@enderror

        <div class="check">
            <input id="terms" name="terms" type="checkbox" value="1" required
                   @checked(old('terms'))
                   @error('terms') aria-invalid="true" aria-describedby="terms-error" @enderror>
            <label for="terms" style="margin:0;font-weight:400">
                קראתי ואני מאשר/ת את תנאי השימוש ומדיניות הפרטיות, ואת החידוש האוטומטי של המנוי.
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
