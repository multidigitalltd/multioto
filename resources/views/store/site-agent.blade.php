@php
    /**
     * עמוד המכירה של בוט ניהול האתר.
     *
     * The form asks for one thing the plugin store never had to: the phone
     * number that will drive the site. It is the product — not a contact detail
     * — so it is asked for with the same weight as the email, and the page says
     * out loud what will happen to it, because a six-digit code arriving from an
     * unknown number is otherwise indistinguishable from a scam.
     *
     * Prices are quoted NET, with "+ מע״מ" beside them. The buyer is a business
     * and reclaims the VAT, so a gross figure reads as 18% dearer than every
     * competitor quoting net — and a net figure with no VAT named is the sentence
     * a customer later disputes against their invoice. Everything that actually
     * bills still goes through the gross helpers, VAT-exempt flag included.
     *
     * The running total is computed in JS as a convenience only. Without it the
     * page still states the plan's price, the price per extra number and how
     * many were chosen, and the authoritative figure is the one Cardcom's own
     * page shows before anybody types a card number.
     */
    use App\Support\Money;

    /*
     | One plan is the shape this product is sold in, but the query behind
     | $plans does not promise it — and a page that quotes the FIRST plan's terms
     | while a buyer has selected the second is a page that sold a per-message
     | charge, or withheld a trial, without saying so.
     |
     | So: every plan prints its OWN terms (the loop below), the form repeats the
     | ones that change what is charged beside each option, and anything written
     | as a single sentence about "the plan" is printed only when there is in fact
     | one. $headline is used exclusively where "the first/cheapest" is the honest
     | reading — the hero, and the default selection.
     */
    $headline = $plans->first();
    $single = $plans->count() === 1;

    // Only promised where EVERY plan carries it. "ניסיון חינם" in the hero over a
    // list where one plan has none is the hero making a promise the page breaks.
    $allHaveTrial = $plans->every(fn ($plan) => $plan->hasTrial());

    // Plan figures for the running total, by id. Net agorot only — the page
    // quotes net, and the VAT line says so once rather than per row.
    $planData = $plans->mapWithKeys(fn ($plan) => [$plan->id => [
        'net' => (int) $plan->price_agorot,
        'extra' => (int) ($plan->extra_number_price_agorot ?? 0),
        'sells_extra' => $plan->sellsExtraNumbers(),
        'interval' => $plan->intervalLabel(),
        'vat' => (bool) $plan->vat_applies,
        'trial' => (int) $plan->trial_days,
        // The usage charge cannot be part of a total — it is not known yet — but
        // it must follow the selection, or picking a plan that bills messages
        // leaves the figure beside the button belonging to one that does not.
        'message' => $plan->messageNetLabel(),
    ]]);

    $anySellsExtra = $plans->contains(fn ($plan) => $plan->sellsExtraNumbers());
@endphp
    <!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>בוט ניהול האתר — האתר שלכם מנוהל מוואטסאפ</title>
    <meta name="description" content="שולחים הודעה בוואטסאפ, והאתר מתעדכן. הזמנות, לידים, מחירים, תמונות וטקסטים — באישור שלכם, בלי להיכנס לוורדפרס.">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #16181d; --bg-2: #1d2027; --card: #22252d; --card-2: #2a2e38;
            --fg: #f4f5f7; --muted: #aab1bd; --border: #3a3f4b;
            --field: #f3f1ee; --field-fg: #16181d;
            --brand: #ec4899; --brand-2: #8b5cf6; --brand-fg: #ffffff;
            --error: #ff8a8a; --ok: #4ade80; --accent-soft: rgb(236 72 153 / .1);
            --focus: #ffd166;
            --radius: 16px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.65;
            -webkit-text-size-adjust: 100%;
        }

        .wrap { max-width: 56rem; margin: 0 auto; padding: 0 1rem 3rem; }

        a { color: var(--brand); }
        a:hover { color: var(--brand-2); }

        /* 3:1 against what is behind it in both schemes — a focus ring nobody can
           see is a page that cannot be used from a keyboard. */
        :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 4px; }

        /* ---------- Hero ---------- */

        .hero {
            background:
                radial-gradient(60rem 28rem at 85% -15%, rgb(139 92 246 / .35), transparent 60%),
                radial-gradient(45rem 24rem at 10% 0%, rgb(236 72 153 / .3), transparent 60%),
                var(--bg-2);
            border-bottom: 1px solid var(--border);
            padding: clamp(2rem, 7vw, 4rem) 0 clamp(1.75rem, 5vw, 3rem);
        }

        .badge {
            display: inline-flex; align-items: center; gap: .45rem;
            background: var(--accent-soft); border: 1px solid var(--brand);
            color: var(--fg); border-radius: 999px;
            padding: .35rem .85rem; font-size: .9rem; font-weight: 600;
            margin-bottom: 1rem;
        }

        h1 {
            font-size: clamp(1.75rem, 6.5vw, 3rem);
            line-height: 1.15; margin: 0 0 .6rem; letter-spacing: -.02em;
        }

        .hero .sub { font-size: clamp(1.05rem, 2.6vw, 1.3rem); color: var(--muted); margin: 0 0 1.5rem; max-width: 42rem; }

        .hero-price { display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem 1rem; margin-bottom: 1.5rem; }
        .hero-price .amount { font-size: clamp(1.5rem, 4.5vw, 2.1rem); font-weight: 800; }
        .hero-price .vat { color: var(--muted); font-size: 1rem; }

        .cta {
            display: inline-block; padding: .85rem 1.75rem; border-radius: 999px;
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            color: var(--brand-fg); font-weight: 700; text-decoration: none; font-size: 1.05rem;
        }
        .cta:hover { filter: brightness(1.08); color: var(--brand-fg); }

        /* ---------- Sections ---------- */

        section { margin-top: clamp(2.25rem, 6vw, 3.5rem); }
        h2 { font-size: clamp(1.3rem, 3.5vw, 1.75rem); margin: 0 0 .4rem; letter-spacing: -.01em; }
        h3 { font-size: 1.05rem; margin: 0 0 .5rem; }
        .section-lead { color: var(--muted); margin: 0 0 1.5rem; max-width: 44rem; }

        .cards { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }

        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 1.25rem;
        }
        .card h3 { display: flex; align-items: center; gap: .5rem; }
        .card .tier { font-size: .8rem; font-weight: 700; color: var(--brand); letter-spacing: .04em; }

        ul.quotes { list-style: none; margin: .75rem 0 0; padding: 0; display: grid; gap: .55rem; }
        ul.quotes li {
            background: var(--card-2); border-radius: 12px; border: 1px solid var(--border);
            padding: .6rem .8rem; font-size: .95rem;
        }
        /* Decorative, and told so: the empty alt text after the slash keeps a
           screen reader from announcing "speech balloon" before every example.
           A browser that does not understand the syntax drops the marker
           entirely, which is the right way to lose it. */
        ul.quotes li::before { content: "💬" / ""; padding-inline-end: .35rem; }

        .promise {
            background: var(--card); border: 1px solid var(--brand);
            border-radius: var(--radius); padding: 1.1rem 1.25rem;
        }
        .promise strong { color: var(--fg); }

        ol.steps { counter-reset: step; list-style: none; margin: 0; padding: 0; display: grid; gap: .9rem; }
        ol.steps li {
            counter-increment: step; position: relative;
            padding-inline-start: 2.75rem; color: var(--muted);
        }
        ol.steps li::before {
            content: counter(step); position: absolute; inset-inline-start: 0; top: 0;
            width: 2rem; height: 2rem; border-radius: 999px;
            background: var(--accent-soft); border: 1px solid var(--brand); color: var(--fg);
            display: grid; place-items: center; font-weight: 700; font-size: .95rem;
        }
        ol.steps li strong { color: var(--fg); }

        ul.plain { color: var(--muted); margin: 0; padding-inline-start: 1.2rem; }
        ul.plain li { margin-bottom: .35rem; }

        /* ---------- Pricing ---------- */

        .price-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: clamp(1.1rem, 3vw, 1.75rem);
        }
        .price-card .name { font-weight: 700; font-size: 1.1rem; }
        .price-card .big { font-size: clamp(1.75rem, 5vw, 2.4rem); font-weight: 800; line-height: 1.2; }
        .price-card .vat { color: var(--muted); font-size: 1rem; font-weight: 400; }

        dl.includes { margin: 1.1rem 0 0; display: grid; gap: .5rem; }
        /* dt and dd on one line where there is room. The tick lives INSIDE the dt
           rather than beside it, because a dl may only hold dt and dd — a span
           between them is markup a screen reader is entitled to ignore. */
        dl.includes div { display: flex; flex-wrap: wrap; gap: 0 .4rem; align-items: baseline; }
        dl.includes dt { font-weight: 600; min-width: 0; }
        dl.includes dd { margin: 0; color: var(--muted); flex: 1 1 14rem; }
        dl.includes .tick { color: var(--ok); }

        .note { color: var(--muted); font-size: .92rem; }

        /* ---------- Form ---------- */

        form { margin-top: 1.25rem; }
        fieldset { border: 0; margin: 0 0 1rem; padding: 0; min-width: 0; }
        legend { font-weight: 700; font-size: 1.05rem; padding: 0; margin-bottom: .6rem; }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        .req { color: var(--brand); }

        input[type=text], input[type=email], input[type=tel], input[type=url] {
            width: 100%; padding: .7rem .8rem; border-radius: 10px;
            border: 1px solid var(--border); background: var(--field); color: var(--field-fg); font: inherit;
        }
        input[aria-invalid=true] { border-color: var(--error); border-width: 2px; }

        .hint { color: var(--muted); font-size: .9rem; margin: .3rem 0 0; }
        .error { color: var(--error); font-size: .9rem; margin: .3rem 0 0; font-weight: 600; }

        .opt {
            display: flex; gap: .7rem; align-items: flex-start; border: 1px solid var(--border);
            border-radius: 12px; padding: .9rem 1rem; margin-bottom: .6rem; cursor: pointer; font-weight: 400;
        }
        .opt:hover { border-color: var(--brand); }
        .opt input { margin-top: .35rem; width: 1.15rem; height: 1.15rem; flex: none; }
        .opt:has(input:checked) { border-color: var(--brand); background: var(--accent-soft); }
        .opt .body { display: flex; flex-direction: column; gap: .15rem; min-width: 0; }
        .opt .title { font-weight: 700; }
        .opt .meta { color: var(--muted); font-size: .9rem; }

        .extras { display: grid; gap: .6rem; }
        .extras .row { display: grid; gap: .25rem; }
        .extras label { margin: 0; font-weight: 400; font-size: .92rem; color: var(--muted); }

        .total {
            background: var(--card-2); border: 1px solid var(--brand);
            border-radius: 12px; padding: .9rem 1.1rem; margin: 1.1rem 0;
        }
        .total .figure { font-size: 1.25rem; font-weight: 800; }

        .check { display: flex; gap: .6rem; align-items: flex-start; margin: 1.25rem 0; }
        .check input { margin-top: .35rem; width: 1.1rem; height: 1.1rem; flex: none; }
        .check label { margin: 0; font-weight: 400; }

        button[type=submit] {
            width: 100%; padding: .95rem 1rem; border: 0; border-radius: 999px; font: inherit;
            font-weight: 700; font-size: 1.05rem; cursor: pointer;
            background: linear-gradient(135deg, var(--brand), var(--brand-2)); color: var(--brand-fg);
        }
        button[type=submit]:hover { filter: brightness(1.08); }

        .errors {
            background: rgb(255 138 138 / .12); border: 1px solid var(--error);
            border-radius: 12px; padding: .85rem 1.1rem; margin-bottom: 1.25rem;
        }
        .errors ul { margin: .35rem 0 0; padding-inline-start: 1.1rem; }

        /* ---------- FAQ ---------- */

        .faq { display: grid; gap: .6rem; }
        .faq details {
            background: var(--card); border: 1px solid var(--border); border-radius: 12px;
            padding: .85rem 1.1rem;
        }
        .faq details[open] { border-color: var(--brand); }
        .faq summary { cursor: pointer; font-weight: 600; }
        .faq summary::marker { color: var(--brand); }
        .faq p { color: var(--muted); margin: .6rem 0 0; }

        .foot { color: var(--muted); font-size: .9rem; margin-top: 2.5rem; text-align: center; }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; scroll-behavior: auto !important; }
        }

        @media (prefers-color-scheme: light) {
            :root {
                --bg: #ffffff; --bg-2: #fdf7fb; --card: #ffffff; --card-2: #f7f5f9;
                --fg: #16181d; --muted: #555b66; --border: #d8dbe2;
                --field: #ffffff; --field-fg: #16181d;
                --ok: #15803d;
                /* Darker on white. The dark-scheme pink is about 3.3:1 against a
                   white page, which is below the 4.5:1 a link needs — and links
                   are the one thing on the page somebody has to be able to find. */
                --brand: #be185d; --brand-2: #6d28d9; --focus: #be185d;
            }
            .card, .price-card, .faq details { box-shadow: 0 1px 3px rgb(0 0 0 / .06); }
        }
    </style>
</head>
<body>

<header class="hero">
    <div class="wrap">
        @if ($allHaveTrial)
            <p class="badge"><span aria-hidden="true">✨</span> {{ $headline->trial_days }} ימים ניסיון חינם</p>
        @endif

        <h1>האתר שלכם מנוהל מוואטסאפ</h1>
        <p class="sub">
            שולחים הודעה — והאתר מתעדכן. שואלים שאלה — ומקבלים תשובה מהאתר עצמו.
            בלי להיכנס לוורדפרס, בלי לחכות לאף אחד.
        </p>

        <p class="hero-price">
            {{-- $plans is ordered by price, so the first is the cheapest. With
                 more than one, "מ־" rather than a figure stated as the price. --}}
            <span class="amount">{{ $single ? '' : 'מ־' }}{{ Money::ils((int) $headline->price_agorot) }} {{ $headline->intervalLabel() }}</span>
            @if ($headline->vat_applies)
                <span class="vat">+ מע״מ</span>
            @endif
        </p>

        <a class="cta" href="#buy">מתחילים — {{ $allHaveTrial ? $headline->trial_days.' ימים בחינם' : 'רכישה' }}</a>
    </div>
</header>

<div class="wrap">

    {{-- ====================== What it can do ====================== --}}

    <section aria-labelledby="can-do">
        <h2 id="can-do">מה אפשר לבקש ממנו</h2>
        <p class="section-lead">
            לא תפריט של שלוש פעולות. זה עוזר שקורא מהאתר בזמן אמת — הזמנות, לידים, מוצרים, מנויים, תגובות,
            תפריטים, קטגוריות, אזורי משלוח, תוספים ויומן השגיאות — עונה, ומציע שינוי שמתבצע רק אחרי שאישרתם.
        </p>

        <div class="cards">
            <div class="card">
                <h3><span class="tier">פשוט</span> שינויים יומיומיים</h3>
                <ul class="quotes">
                    <li>תחליף את הטלפון בעמוד צור קשר ל־03-1234567</li>
                    <li>תעלה את התמונה הזאת לעמוד הבית</li>
                    <li>תעדכן את המחיר של הכורסה ל־1,290 ש״ח</li>
                    <li>תוסיף 20 יחידות למלאי של החולצה הלבנה</li>
                    <li>תעלה מוצר חדש: חולצת פשתן, 120 ש״ח, 5 במלאי, בקטגוריית חולצות</li>
                    {{-- A photo with a caption is the shortest path there is from
                         "I have a new product" to it being on the site. --}}
                    <li>[תמונה] מוצר חדש, כד קרמיקה, 89 ש״ח</li>
                </ul>
            </div>

            <div class="card">
                <h3><span class="tier">שאלות</span> מה קורה באתר</h3>
                <ul class="quotes">
                    <li>כמה הזמנות היו השבוע ומה המכירות?</li>
                    <li>מי השאיר פרטים אתמול בטופס?</li>
                    <li>יש תגובות שמחכות לאישור?</li>
                    <li>למה המשלוח לאילת יוצא 80 שקל?</li>
                    <li>יש עדכוני תוספים? מה גרסת ה־PHP?</li>
                    <li>יש שגיאות באתר מאתמול?</li>
                </ul>
            </div>

            <div class="card">
                <h3><span class="tier">מסובך</span> דברים שבאמת חוסכים זמן</h3>
                <ul class="quotes">
                    <li>תשווה את המכירות של החודש לקודם ותגיד לי אילו מוצרים ירדו</li>
                    <li>תעבור על ההזמנות בהמתנה מהשבוע, תגיד לי מי שילם בהעברה, ותסמן אותן כהושלמו</li>
                    <li>תודיע לי על כל ליד חדש</li>
                    <li>כל בוקר בשמונה תשלח לי את המכירות של אתמול והלידים החדשים</li>
                    <li>תייצר קופון 15% לשבוע הקרוב בשם SUKKOT ותכתוב עליו פוסט כטיוטה</li>
                    <li>בעמוד הנחיתה באלמנטור — במקום "חייגו עכשיו" שיהיה "השאירו פרטים"</li>
                    <li>תוסיף את רונית כעורכת באתר</li>
                    <li>המנוי של יוסי — תשהה אותו עד שיסדיר תשלום</li>
                    <li>תעדכן את התוספים שיש להם עדכון ותוודא שהאתר עולה</li>
                    <li>תוסיף את העמוד "תקנון" לתפריט התחתון</li>
                    <li>השינוי לא מופיע באתר — תנקה מטמון</li>
                </ul>
            </div>
        </div>
    </section>

    {{-- The sentence that decides whether somebody trusts this at all. An agent
         that can edit a business's website has to be one the owner approves each
         change on, and saying so before the price is the difference between a
         product and a risk. --}}

    <section aria-labelledby="promise-title">
        <div class="promise">
            <h2 id="promise-title" style="font-size:1.15rem">שום דבר לא קורה בלי "כן" שלכם</h2>
            <p style="margin:.5rem 0 0">
                כל שינוי מוצג לכם בצ׳אט <strong>לפני</strong> שהוא מבוצע, עם כפתורי "כן" ו"לא"
                (ואפשר גם פשוט להקליד), ולרוב השינויים יש "בטל".
                {{-- Named, not glossed over. A product that lists what cannot be
                     undone is a product somebody can trust with the rest; one
                     that promises "everything is reversible" is caught out once
                     and never trusted again. --}}
                ומה שאי אפשר להחזיר נאמר במפורש לפני האישור: הערה שנשלחת ללקוח באימייל, ביטול מנוי,
                משתמש חדש, מחיקת קובץ מספריית המדיה.
                הבוט אינו נוגע בעיצוב, בקוד או במסד הנתונים, ואינו מבצע החזרים כספיים.
            </p>
        </div>
    </section>

    {{-- ====================== How it works ====================== --}}

    <section aria-labelledby="how">
        <h2 id="how">איך זה עובד</h2>
        <ol class="steps">
            <li><strong>נרשמים כאן</strong> ומזינים את מספר הוואטסאפ שינהל את האתר.</li>
            <li><strong>מתקינים תוסף</strong> באתר הוורדפרס — כ־5 דקות, או שנתקין עבורכם ללא תשלום.</li>
            <li><strong>מאמתים את המספר</strong> בקוד בן 6 ספרות שמגיע בוואטסאפ מהמספר של הבוט.</li>
            <li><strong>כותבים לו.</strong> מכאן זו שיחה רגילה.</li>
        </ol>
    </section>

    {{-- ====================== Pricing ====================== --}}

    <section aria-labelledby="price-title">
        <h2 id="price-title">{{ $single ? 'המחיר' : 'המסלולים' }}</h2>
        <p class="section-lead">כל המחירים בעמוד זה הם לפני מע״מ.</p>

        {{-- A card per plan, each stating ITS OWN terms. The trial, the price of
             an extra number and the per-message charge are what a buyer is
             actually agreeing to, and they differ between plans — printing the
             first plan's set above a list the buyer can choose from is how
             somebody buys a usage charge they were never shown. --}}
        @foreach ($plans as $plan)
        <div class="price-card" @unless ($loop->first) style="margin-top:1rem" @endunless>
            <p class="name" style="margin:0">{{ $plan->name }}</p>
            <p class="big" style="margin:.2rem 0 0">
                {{ Money::ils((int) $plan->price_agorot) }} {{ $plan->intervalLabel() }}
                @if ($plan->vat_applies)
                    <span class="vat">+ מע״מ</span>
                @endif
            </p>

            @if (filled($plan->description))
                <p class="note" style="margin:.4rem 0 0">{{ $plan->description }}</p>
            @endif

            <dl class="includes">
                @if ($plan->hasTrial())
                    <div>
                        <dt><span class="tick" aria-hidden="true">✓</span> {{ $plan->trial_days }} ימים ניסיון חינם.</dt>
                        <dd>
                            מזינים כרטיס ולא מחויבים — החיוב הראשון ביום ה־{{ $plan->trial_days + 1 }},
                            ואפשר לבטל לפני כן. תזכורת תישלח יומיים קודם.
                        </dd>
                    </div>
                @endif

                <div>
                    <dt><span class="tick" aria-hidden="true">✓</span> מספר אחד שמנהל את האתר</dt>
                    <dd>— כלול במחיר.</dd>
                </div>

                @if ($plan->sellsExtraNumbers())
                    <div>
                        <dt><span class="tick" aria-hidden="true">✓</span> מספר נוסף:</dt>
                        <dd>{{ $plan->extraNumberNetLabel() }} — אפשר להוסיף כאן בקנייה, או בכל שלב מהאזור האישי.</dd>
                    </div>
                @endif

                {{-- Said here, in the price, and not in a footnote. A charge a
                     customer discovers on their first invoice is a charge they
                     dispute, however reasonable it is. --}}
                @if ($plan->billsMessages())
                    <div>
                        <dt><span class="tick" aria-hidden="true">✓</span> הודעות:</dt>
                        <dd>
                            @if ((int) $plan->included_messages > 0)
                                {{ number_format($plan->included_messages) }} הודעות בכל חודש כלולות במחיר; מעבר להן —
                                {{ $plan->messageNetLabel() }} להודעה, נגבה בחידוש החודשי לפי הספירה.
                            @else
                                לכל הודעה שהבוט שולח לכם — {{ $plan->messageNetLabel() }}, נגבה בחידוש החודשי לפי הספירה.
                            @endif
                            קודי אימות והודעות מערכת אינם נספרים@if ($plan->hasTrial()), והודעות בתקופת הניסיון אינן מחויבות@endif.
                            {{-- The trial clause only where there is a trial: naming
                                 one on a plan that has none offers something this
                                 plan does not include. --}}
                            את הספירה אפשר לראות בכל רגע באזור האישי, או לשאול את הבוט "כמה הודעות שלחתי החודש?".
                            ואפשר לקבוע תקרה: בתקרה הבוט עוצר עד החידוש, ושום דבר מעליה לא מחויב.
                        </dd>
                    </div>
                @endif

                <div>
                    <dt><span class="tick" aria-hidden="true">✓</span> מתחדש אוטומטית.</dt>
                    <dd>אפשר לבטל בכל עת, ולא תחויבו לתקופה הבאה.</dd>
                </div>
            </dl>
        </div>
        @endforeach
    </section>

    {{-- ====================== The form ====================== --}}

    <section id="buy" aria-labelledby="buy-title">
        <h2 id="buy-title">הרשמה</h2>
        <p class="section-lead">
            @if ($allHaveTrial)
                מזינים כרטיס, לא מחויבים היום, ואפשר לבטל בתוך {{ $headline->trial_days }} הימים.
            @else
                התשלום מתבצע בעמוד מאובטח של חברת הסליקה.
            @endif
        </p>

        @if ($errors->any())
            <div class="errors" role="alert">
                <strong>לא ניתן להמשיך:</strong>
                <ul>
                    @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form id="buy-form" method="POST" action="{{ route('store.agent.buy') }}" novalidate
              data-plans="{{ json_encode($planData, JSON_UNESCAPED_UNICODE) }}">
            @csrf

            @if ($single)
                {{-- One plan, so there is nothing to choose: a radio group of one
                     is a decision a buyer has to make about nothing. --}}
                <input type="hidden" name="plan" value="{{ $headline->id }}">
            @else
                <fieldset>
                    <legend>המסלול</legend>
                    @foreach ($plans as $plan)
                        <label class="opt" for="plan-{{ $plan->id }}">
                            <input type="radio" name="plan" id="plan-{{ $plan->id }}" value="{{ $plan->id }}"
                                   required @checked(old('plan', $headline->id) == $plan->id)>
                            {{-- The terms that change what is charged, repeated
                                 beside the option itself. The cards above state
                                 them in full, but this is the control somebody
                                 actually clicks, and a buyer who scrolled past
                                 the cards must not pick a plan whose usage
                                 charge or missing trial they never saw. --}}
                            <span class="body">
                                <span class="title">{{ $plan->name }}</span>
                                <span class="meta">{{ $plan->netPriceLabel() }}</span>
                                @if ($plan->hasTrial())
                                    <span class="meta">{{ $plan->trial_days }} ימים ניסיון חינם, עם כרטיס ובלי חיוב.</span>
                                @endif
                                @if ($plan->billsMessages())
                                    <span class="meta">
                                        @if ((int) $plan->included_messages > 0)
                                            {{ number_format($plan->included_messages) }} הודעות בחודש כלולות; מעבר להן {{ $plan->messageNetLabel() }} להודעה.
                                        @else
                                            בנוסף {{ $plan->messageNetLabel() }} לכל הודעה שהבוט שולח לכם.
                                        @endif
                                    </span>
                                @endif
                                @if ($plan->sellsExtraNumbers())
                                    <span class="meta">מספר נוסף: {{ $plan->extraNumberNetLabel() }}.</span>
                                @endif
                                @if (filled($plan->description))
                                    <span class="meta">{{ $plan->description }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                    @error('plan')<p class="error">{{ $message }}</p>@enderror
                </fieldset>
            @endif

            <fieldset>
                <legend>הפרטים שלכם</legend>

                <label for="name">שם מלא <span class="req" aria-hidden="true">*</span></label>
                <input id="name" name="name" type="text" required autocomplete="name"
                       value="{{ old('name') }}"
                       @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror

                <label for="email">אימייל <span class="req" aria-hidden="true">*</span></label>
                <input id="email" name="email" type="email" required autocomplete="email" inputmode="email"
                       value="{{ old('email') }}"
                       @error('email') aria-invalid="true" @enderror
                       aria-describedby="email-hint @error('email') email-error @enderror">
                <p class="hint" id="email-hint">לכתובת הזו תישלח החשבונית והוראות ההפעלה.</p>
                @error('email')<p class="error" id="email-error">{{ $message }}</p>@enderror

                <label for="domain">כתובת האתר <span class="req" aria-hidden="true">*</span></label>
                <input id="domain" name="domain" type="text" required dir="ltr" placeholder="example.co.il"
                       value="{{ old('domain') }}"
                       @error('domain') aria-invalid="true" @enderror
                       aria-describedby="domain-hint @error('domain') domain-error @enderror">
                <p class="hint" id="domain-hint">האתר שהבוט ינהל. אתר וורדפרס.</p>
                @error('domain')<p class="error" id="domain-error">{{ $message }}</p>@enderror
            </fieldset>

            <fieldset>
                <legend>המספר שינהל את האתר</legend>

                <label for="phone">מספר וואטסאפ <span class="req" aria-hidden="true">*</span></label>
                <input id="phone" name="phone" type="tel" required autocomplete="tel" inputmode="tel" dir="ltr"
                       placeholder="050-1234567"
                       value="{{ old('phone') }}"
                       @error('phone') aria-invalid="true" @enderror
                       aria-describedby="phone-hint @error('phone') phone-error @enderror">
                {{-- Said before they type it, not after: a code from an unfamiliar
                     number is otherwise indistinguishable from the scam everybody
                     has been warned about, and the ones who are careful are the
                     ones who will not answer it. --}}
                <p class="hint" id="phone-hint">
                    מיד אחרי התשלום יישלח למספר הזה קוד בן 6 ספרות בוואטסאפ. יש להשיב עליו באותה שיחה —
                    עד אז המספר אינו יכול לעשות דבר באתר.
                </p>
                @error('phone')<p class="error" id="phone-error">{{ $message }}</p>@enderror

                <label for="manager_name">שם מנהל האתר</label>
                <input id="manager_name" name="manager_name" type="text" autocomplete="name"
                       value="{{ old('manager_name') }}" aria-describedby="manager-hint">
                <p class="hint" id="manager-hint">אופציונלי. אם המספר אינו שלכם — למשל של מנהלת המשרד.</p>
            </fieldset>

            @if ($anySellsExtra)
                <fieldset id="extras-block">
                    <legend>מספרים נוספים (אופציונלי)</legend>
                    <p class="hint" id="extras-hint" style="margin:0 0 .7rem">
                        שותף, מנהלת משרד או מישהו מהצוות שגם ינהל את האתר.
                        <span id="extras-price">{{ $headline->extraNumberNetLabel() ?? $plans->firstWhere(fn ($plan) => $plan->sellsExtraNumbers())->extraNumberNetLabel() }}</span> לכל מספר.
                        כל מספר מקבל קוד אימות משלו, ואפשר לבטל מספר בכל עת מהאזור האישי.
                        עד {{ $maxExtraNumbers }} כאן — נוספים מתווספים אחר כך מהאזור האישי.
                    </p>

                    <div class="extras">
                        @for ($i = 0; $i < $maxExtraNumbers; $i++)
                            <div class="row">
                                <label for="extra-{{ $i }}">מספר נוסף {{ $i + 1 }}</label>
                                <input id="extra-{{ $i }}" name="extra_phones[]" type="tel" dir="ltr"
                                       inputmode="tel" placeholder="050-1234567"
                                       value="{{ old('extra_phones.'.$i) }}"
                                       aria-describedby="extras-hint">
                            </div>
                        @endfor
                    </div>
                    @error('extra_phones')<p class="error">{{ $message }}</p>@enderror
                    @error('extra_phones.*')<p class="error">{{ $message }}</p>@enderror
                </fieldset>
            @endif

            {{-- The running total. Filled in by script; without one it states the
                 default selection's price, which is what the figure is when
                 nothing has been added or chosen. The figure follows the chosen
                 plan — including its per-message charge, which cannot be part of
                 a total but must not belong to a different plan either. --}}
            <p class="total" id="total" role="status" aria-live="polite">
                <span class="figure" id="total-figure">{{ $headline->netPriceLabel() }}</span><br>
                <span class="note" id="total-note">
                    @if ($headline->hasTrial())
                        היום לא תחויבו. החיוב הראשון בתום {{ $headline->trial_days }} ימי הניסיון.
                    @else
                        סה״כ לתשלום היום.
                    @endif
                    @if ($headline->billsMessages())
                        ובנוסף {{ $headline->messageNetLabel() }} לכל הודעה שהבוט שולח לכם.
                    @endif
                </span>
            </p>

            <fieldset>
                <legend>ההתקנה</legend>

                <label class="opt" for="install-self">
                    <input type="radio" name="install_mode" id="install-self"
                           value="{{ \App\Models\SiteAgentOrder::INSTALL_SELF }}"
                           required @checked(old('install_mode', \App\Models\SiteAgentOrder::INSTALL_SELF) === \App\Models\SiteAgentOrder::INSTALL_SELF)>
                    <span class="body">
                        <span class="title">אני אתקין לבד</span>
                        <span class="meta">מיד אחרי התשלום תקבלו את קובץ התוסף והקודים להדבקה. כחמש דקות.</span>
                    </span>
                </label>

                <label class="opt" for="install-us">
                    <input type="radio" name="install_mode" id="install-us"
                           value="{{ \App\Models\SiteAgentOrder::INSTALL_BY_US }}"
                           @checked(old('install_mode') === \App\Models\SiteAgentOrder::INSTALL_BY_US)>
                    <span class="body">
                        <span class="title">תתקינו לי</span>
                        {{-- The cost of this option is stated here rather than
                             discovered on the next screen: it requires handing us
                             administrator access, and somebody who would rather
                             not should be choosing the other option now. --}}
                        <span class="meta">
                            נתקין עבורכם, ללא תשלום נוסף. בעמוד הבא תתבקשו לתת גישת מנהל לאתר —
                            עדיף קישור התחברות זמני שפג מעצמו. הגישה נמחקת אצלנו בתום ההתקנה.
                        </span>
                    </span>
                </label>
                @error('install_mode')<p class="error">{{ $message }}</p>@enderror
            </fieldset>

            <div class="check">
                <input id="terms" name="terms" type="checkbox" value="1" required
                       @checked(old('terms'))
                       @error('terms') aria-invalid="true" aria-describedby="terms-error" @enderror>
                <label for="terms">
                    קראתי ואני מאשר/ת את <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">תנאי השימוש</a>
                    ו<a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">מדיניות הפרטיות</a>,
                    ואת החידוש האוטומטי של המנוי.
                </label>
            </div>
            @error('terms')<p class="error" id="terms-error">{{ $message }}</p>@enderror

            <button type="submit">
                {{ $allHaveTrial ? 'מתחילים את תקופת הניסיון' : 'מעבר לתשלום מאובטח' }}
            </button>
        </form>
    </section>

    {{-- ====================== FAQ ====================== --}}

    <section aria-labelledby="faq-title">
        <h2 id="faq-title">שאלות נפוצות</h2>

        <div class="faq">
            <details>
                <summary>מה הבוט לא עושה?</summary>
                <p>
                    לא נוגע בעיצוב, בקוד או במסד הנתונים, ולא מתקין תוספים חדשים. לא מבצע החזרים כספיים
                    ולא מוחק הזמנות — כסף שחוזר ללקוח נעשה בידי אדם בניהול האתר, זו החלטה שאי אפשר
                    להחזיר. ומה שהוא כן עושה בתחום הזה, רק באישור: פוסט או עמוד עוברים
                    <strong>לפח</strong> (הפיך), קובץ מספריית המדיה נמחק סופית ונאמר כך לפני האישור,
                    תוספים ותבניות מתעדכנים ואחרי כל עדכון נבדק שהאתר עולה, ואפשר לכבות תוסף — חוץ
                    מהחנות, אלמנטור, תוספי אבטחה ותוסף החיבור עצמו, שאינם נכבים מהצ׳אט בכלל.
                </p>
            </details>

            <details>
                <summary>האתר שלי לא בוורדפרס — זה יעבוד?</summary>
                <p>
                    לא. הבוט עובד עם אתרי וורדפרס, דרך תוסף שמותקן באתר. חלק מהיכולות
                    (הזמנות, מוצרים, דוח מכירות) דורשות ווקומרס, וחלק (מנויים מתחדשים)
                    דורשות גם את WooCommerce Subscriptions.
                </p>
            </details>

            <details>
                <summary>איך אני בטוח שהוא לא ישנה משהו בטעות?</summary>
                <p>
                    כל שינוי מוצג לפני הביצוע ומתבצע רק אחרי "כן", ולכל שינוי יש "בטל".
                    התצוגה נכתבת מהנתונים שהאתר החזיר — שם המוצר האמיתי, הסטטוס האמיתי —
                    ולא מהניסוח של הבוט. ובביצוע עצמו: הזמנה משתנה רק אם היא עדיין בסטטוס
                    שראיתם, כך ששינוי שקרה בינתיים לא נדרס.
                </p>
            </details>

            <details>
                <summary>מי יכול לשלוח הוראות לאתר שלי?</summary>
                <p>
                    רק מספר שאומת מול האתר הזה. מספר לא מוכר מקבל "המספר אינו רשום" ולא יותר מזה.
                    אפשר לחבר מספרים נוספים (שותף, מנהלת משרד) — כל אחד בתשלום ועם קוד אימות משלו —
                    ולבטל מספר בכל עת מהאזור האישי.
                </p>
            </details>

            {{-- Shown when ANY plan has it, so the answer is never missing. A
                 figure is quoted only where there is one plan to quote — with
                 several it points back at the cards, which state each plan's
                 own, rather than naming one plan's price as "the" price. --}}
            @if ($plans->contains(fn ($plan) => $plan->billsMessages()))
                <details>
                    <summary>למה יש חיוב על הודעות, ואיך אני יודע כמה?</summary>
                    <p>
                        כל הודעה שהבוט שולח לכם בוואטסאפ עולה לנו כסף למטא, ולכן היא מחויבת:
                        {{ $single ? $headline->messageNetLabel().' להודעה' : 'המחיר להודעה מופיע בכל מסלול למעלה' }},
                        נגבה בחידוש החודשי לפי הספירה של אותו מחזור. קודי אימות והודעות מערכת
                        אינם נספרים@if ($plans->contains(fn ($plan) => $plan->hasTrial() && $plan->billsMessages())), והודעות בתקופת הניסיון אינן מחויבות@endif. הספירה גלויה באזור
                        האישי, ואפשר גם לשאול את הבוט "כמה הודעות שלחתי החודש?" ולקבל גם את
                        הסכום עד כה.
                    </p>
                </details>
            @endif

            @if ($plans->contains(fn ($plan) => $plan->hasTrial()))
                <details>
                    <summary>מה קורה בסוף תקופת הניסיון?</summary>
                    <p>
                        @if ($single)
                            ביום ה־{{ $headline->trial_days + 1 }} יוצא החיוב הראשון, בכרטיס שהזנתם.
                        @else
                            ביום שאחרי היום האחרון יוצא החיוב הראשון, בכרטיס שהזנתם.
                        @endif
                        תזכורת נשלחת יומיים לפני. ביטול לפני כן — ולא תחויבו בכלל.
                        הניסיון הוא פעם אחת ללקוח ופעם אחת לאתר.
                    </p>
                </details>
            @endif

            <details>
                <summary>אפשר לבטל?</summary>
                <p>
                    כן, בכל עת ובלי התחייבות לתקופה. לא תחויבו לתקופה הבאה, והבוט מפסיק לענות
                    בתום התקופה ששולמה. האתר שלכם נשאר בדיוק כמו שהוא — כל השינויים שבוצעו נשארים,
                    והתוסף אפשר להסיר בלחיצה.
                </p>
            </details>

            <details>
                <summary>ההתקנה — כמה זמן, ומה אם לא הסתדרתי?</summary>
                <p>
                    כ־5 דקות: מורידים קובץ, מתקינים בוורדפרס, מדביקים שני קודים. בעמוד שאחרי הרכישה
                    יש מדריך מלא עם פתרון לתקלות הנפוצות (Cloudflare, תוספי אבטחה). ואם לא הסתדרתם —
                    בחרו "תתקינו לי" ונעשה את זה עבורכם ללא תשלום נוסף.
                </p>
            </details>

            <details>
                <summary>הבוט קורא את פרטי הלקוחות שלי?</summary>
                <p>
                    כן, כשאתם שואלים: "מי השאיר פרטים אתמול?" מחזיר לידים אמיתיים.
                    לכן תמליל השיחה נשמר אצלנו לזמן קצר בכוונה — 7 ימים — ונמחק אחר כך,
                    וההקשר שהבוט רואה מוגבל לשיחה של השעות האחרונות.
                </p>
            </details>

            <details>
                <summary>אני רוצה לנהל שני אתרים</summary>
                <p>
                    אפשר. כל אתר הוא מנוי משלו. אותו מספר יכול לנהל את שניהם — הבוט ישאל על איזה
                    אתר מדובר ויזכור את התשובה להמשך השיחה.
                </p>
            </details>
        </div>
    </section>

    <p class="foot">
        התשלום מתבצע בעמוד מאובטח של חברת הסליקה. פרטי האשראי אינם נשמרים אצלנו.
        <br>
        יש שאלה? <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>
        <br>
        <a href="{{ route('legal.terms') }}">תנאי שימוש</a> ·
        <a href="{{ route('legal.privacy') }}">מדיניות פרטיות</a>
    </p>
</div>

<script>
    // The running total, as a convenience. The page is complete without it: the
    // plan's price is already printed, and the authoritative figure is the one
    // Cardcom's own page shows before a card number is typed.
    (function () {
        var form = document.getElementById('buy-form');
        var figure = document.getElementById('total-figure');
        var note = document.getElementById('total-note');

        if (!form || !figure || !note) { return; }

        var plans;
        try { plans = JSON.parse(form.dataset.plans); } catch (e) { return; }

        function money(agorot) {
            return '₪' + (agorot / 100).toLocaleString('he-IL', {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });
        }

        function chosenPlan() {
            var picked = form.querySelector('input[name="plan"]:checked')
                || form.querySelector('input[name="plan"]');

            return picked ? plans[picked.value] : null;
        }

        function extraCount() {
            var filled = 0;

            form.querySelectorAll('input[name="extra_phones[]"]').forEach(function (input) {
                if (input.value.trim() !== '') { filled++; }
            });

            return filled;
        }

        function update() {
            var plan = chosenPlan();

            if (!plan) { return; }

            var extras = plan.sells_extra ? extraCount() : 0;
            var perCycle = plan.net + (extras * plan.extra);
            var vat = plan.vat ? ' + מע״מ' : '';

            figure.textContent = money(perCycle) + ' ' + plan.interval + vat;

            // The extras block is shown whenever ANY plan sells them, so with more
            // than one plan on the page its price belongs to whichever is chosen.
            var block = document.getElementById('extras-block');
            var priceLabel = document.getElementById('extras-price');

            if (block) { block.hidden = !plan.sells_extra; }

            if (priceLabel) {
                priceLabel.textContent = plan.extra === 0
                    ? 'ללא תוספת תשלום'
                    : money(plan.extra) + ' ' + plan.interval + vat;
            }

            var text;

            if (plan.trial > 0) {
                text = 'היום לא תחויבו. החיוב הראשון בתום ' + plan.trial + ' ימי הניסיון'
                    + (extras > 0 ? ', וכולל ' + extras + ' מספרים נוספים.' : '.');
            } else {
                text = extras > 0
                    ? 'סה״כ לתשלום היום, כולל ' + extras + ' מספרים נוספים.'
                    : 'סה״כ לתשלום היום.';
            }

            // The usage charge belongs to the plan that is selected, not to the
            // one the page happened to render first.
            if (plan.message) {
                text += ' ובנוסף ' + plan.message + ' לכל הודעה שהבוט שולח לכם.';
            }

            note.textContent = text;
        }

        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
    })();
</script>
</body>
</html>
