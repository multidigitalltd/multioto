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
    <meta name="theme-color" content="#0a0f1e">
    {{-- Marks the document as scripted before anything paints, so content that
         reveals on scroll is hidden only where a script exists to reveal it. --}}
    <script>document.documentElement.classList.add('js');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700;800&display=swap">
    <style>
        /*
         | A dark page on purpose, in both colour schemes: it is a sales page with
         | one look, not an application that follows the system theme. Every
         | text colour below was chosen against --bg AND --surface-2 at ≥ 4.5:1
         | (muted ≈ 8:1, accent text ≈ 11:1), and field borders at ≥ 3:1 against
         | the card behind them (WCAG 1.4.11).
         */
        :root {
            color-scheme: dark;
            --bg: #0a0f1e; --bg-2: #0d1426;
            --surface: rgb(255 255 255 / .035); --surface-2: #121a2e; --surface-3: #18223a;
            --line: rgb(255 255 255 / .09); --line-strong: rgb(255 255 255 / .16);
            --fg: #f5f7fb; --muted: #a9b2c6; --dim: #8e98ae;
            --green: #25d366; --teal: #2dd4bf; --indigo: #818cf8;
            --accent-text: #5eead4;
            --grad: linear-gradient(120deg, #25d366 0%, #2dd4bf 45%, #818cf8 100%);
            --grad-btn: linear-gradient(120deg, #34e07a 0%, #2dd4bf 100%);
            --on-accent: #04140c;
            --field: #0b1222; --field-line: #6b7690;
            --error: #ff9d9d; --ok: #4ade80; --focus: #ffd166;
            --radius: 20px; --radius-sm: 14px;
            --max: 72rem;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { scroll-behavior: smooth; scroll-padding-top: 5rem; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.65;
            -webkit-text-size-adjust: 100%;
            overflow-x: hidden;
        }

        img, svg { max-width: 100%; }

        .wrap { max-width: var(--max); margin: 0 auto; padding: 0 1rem; }
        @media (min-width: 40rem) { .wrap { padding: 0 1.5rem; } }

        a { color: var(--accent-text); text-underline-offset: .2em; }
        a:hover { color: #99f6e4; }

        /* 3:1 against everything it can sit on — a focus ring nobody can see is a
           page that cannot be used from a keyboard. */
        :focus-visible { outline: 3px solid var(--focus); outline-offset: 3px; border-radius: 6px; }

        .sr-only {
            position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }

        .skip {
            position: absolute; inset-inline-start: 1rem; top: -4rem; z-index: 100;
            background: var(--focus); color: #111; padding: .6rem 1rem; border-radius: 10px;
            font-weight: 700; text-decoration: none;
        }
        .skip:focus { top: 1rem; color: #111; }

        /* ---------- Header ---------- */

        .site-header {
            position: sticky; top: 0; z-index: 50;
            background: rgb(10 15 30 / .78);
            -webkit-backdrop-filter: saturate(140%) blur(14px); backdrop-filter: saturate(140%) blur(14px);
            border-bottom: 1px solid var(--line);
        }
        .site-header .wrap { display: flex; align-items: center; gap: 1rem; min-height: 4rem; }

        .brand {
            display: inline-flex; align-items: center; gap: .6rem;
            color: var(--fg); text-decoration: none; font-weight: 700; font-size: 1.02rem;
            margin-inline-end: auto; white-space: nowrap;
        }
        .brand:hover { color: var(--fg); }
        .brand-mark {
            width: 2rem; height: 2rem; border-radius: 10px; flex: none;
            display: grid; place-items: center; background: var(--grad);
            box-shadow: 0 0 0 1px rgb(255 255 255 / .12) inset, 0 6px 20px -6px rgb(37 211 102 / .6);
        }
        .brand-mark svg { width: 1.1rem; height: 1.1rem; }

        .nav { display: none; }
        .nav ul { list-style: none; margin: 0; padding: 0; display: flex; gap: .25rem; }
        .nav a {
            display: block; padding: .45rem .75rem; border-radius: 999px;
            color: var(--muted); text-decoration: none; font-size: .95rem;
        }
        .nav a:hover { color: var(--fg); background: var(--surface); }
        @media (min-width: 62rem) { .nav { display: block; } }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .8rem 1.5rem; border-radius: 999px; border: 0;
            font: inherit; font-weight: 700; font-size: 1.02rem; line-height: 1.2;
            text-decoration: none; cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
        }
        .btn-primary {
            background: var(--grad-btn); color: var(--on-accent);
            box-shadow: 0 10px 30px -10px rgb(45 212 191 / .55), 0 0 0 1px rgb(255 255 255 / .18) inset;
        }
        .btn-primary:hover { color: var(--on-accent); transform: translateY(-1px); box-shadow: 0 14px 34px -10px rgb(45 212 191 / .7), 0 0 0 1px rgb(255 255 255 / .25) inset; }
        .btn-ghost { background: var(--surface); color: var(--fg); box-shadow: 0 0 0 1px var(--line-strong) inset; }
        .btn-ghost:hover { color: var(--fg); background: rgb(255 255 255 / .07); }
        .btn-sm { padding: .55rem 1.05rem; font-size: .95rem; }

        /* ---------- Hero ---------- */

        .hero { position: relative; overflow: hidden; padding: clamp(2.5rem, 7vw, 5rem) 0 clamp(3rem, 7vw, 5.5rem); }
        .hero::before {
            content: ""; position: absolute; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(38rem 26rem at 78% 8%, rgb(37 211 102 / .20), transparent 65%),
                radial-gradient(36rem 28rem at 12% 30%, rgb(129 140 248 / .20), transparent 65%),
                radial-gradient(30rem 20rem at 50% 110%, rgb(45 212 191 / .12), transparent 70%);
        }
        .hero::after {
            content: ""; position: absolute; inset: 0; z-index: -1; pointer-events: none;
            background-image:
                linear-gradient(rgb(255 255 255 / .045) 1px, transparent 1px),
                linear-gradient(90deg, rgb(255 255 255 / .045) 1px, transparent 1px);
            background-size: 56px 56px;
            -webkit-mask-image: radial-gradient(ellipse at 50% 30%, #000 0%, transparent 70%);
            mask-image: radial-gradient(ellipse at 50% 30%, #000 0%, transparent 70%);
        }

        .hero-grid { display: grid; gap: clamp(2.5rem, 6vw, 4rem); align-items: center; }
        @media (min-width: 62rem) { .hero-grid { grid-template-columns: 1.08fr .92fr; } }

        .badge {
            display: inline-flex; align-items: center; gap: .5rem; margin: 0 0 1.25rem;
            padding: .35rem .9rem .35rem .75rem; border-radius: 999px;
            background: rgb(37 211 102 / .1); color: #b9f5d0;
            box-shadow: 0 0 0 1px rgb(37 211 102 / .35) inset;
            font-size: .9rem; font-weight: 600;
        }
        .badge .dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--green); box-shadow: 0 0 0 4px rgb(37 211 102 / .2); }

        h1 {
            font-size: clamp(2.2rem, 8.2vw, 4.1rem); line-height: 1.06; font-weight: 800;
            letter-spacing: -.025em; margin: 0 0 1.1rem;
        }
        .grad-text {
            color: var(--teal);
            background: var(--grad); -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .lead { font-size: clamp(1.08rem, 2.4vw, 1.3rem); color: var(--muted); margin: 0 0 1.75rem; max-width: 36rem; }

        .hero-price { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .75rem; margin: 0 0 1.5rem; }
        .hero-price .amount { font-size: clamp(1.5rem, 4.5vw, 2rem); font-weight: 800; letter-spacing: -.01em; }
        .hero-price .vat { color: var(--muted); font-size: 1rem; white-space: nowrap; }

        .hero-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-bottom: 2rem; }

        .trust { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .6rem 1.4rem; color: var(--muted); font-size: .95rem; }
        .trust li { display: inline-flex; align-items: center; gap: .45rem; }
        .trust svg { width: 1.1rem; height: 1.1rem; color: var(--green); flex: none; }

        /* ---------- The phone ---------- */

        .demo { display: flex; flex-direction: column; align-items: center; gap: 1rem; margin: 0; position: relative; }
        .demo::before {
            content: ""; position: absolute; width: 80%; height: 70%; top: 12%; z-index: -1;
            background: radial-gradient(closest-side, rgb(45 212 191 / .28), transparent);
            filter: blur(20px);
        }

        .phone {
            width: min(100%, 22rem); height: 36rem;
            border-radius: 2.6rem; padding: .6rem;
            background: linear-gradient(160deg, #2a3350, #111827 40%, #0b0f1c);
            box-shadow:
                0 0 0 1px rgb(255 255 255 / .12) inset,
                0 40px 80px -30px rgb(0 0 0 / .8),
                0 0 0 1px rgb(255 255 255 / .05),
                0 30px 90px -40px rgb(45 212 191 / .45);
        }
        @media (max-width: 24rem) { .phone { height: 33rem; border-radius: 2.2rem; } }

        .screen {
            height: 100%; border-radius: 2.05rem; overflow: hidden;
            display: flex; flex-direction: column;
            background-color: #0b141a;
            background-image:
                radial-gradient(circle at 20% 20%, rgb(255 255 255 / .025) 0 2px, transparent 3px),
                radial-gradient(circle at 70% 60%, rgb(255 255 255 / .02) 0 2px, transparent 3px);
            background-size: 38px 38px, 52px 52px;
        }
        @media (max-width: 24rem) { .screen { border-radius: 1.7rem; } }

        .chat-head {
            display: flex; align-items: center; gap: .6rem; flex: none;
            padding: 1.35rem .9rem .7rem; background: #1f2c34; color: #e9edef;
        }
        .chat-head .avatar {
            width: 2.3rem; height: 2.3rem; border-radius: 50%; flex: none;
            display: grid; place-items: center; background: var(--grad);
        }
        .chat-head .avatar svg { width: 1.2rem; height: 1.2rem; }
        .chat-head .who { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
        .chat-head .who b { font-weight: 600; font-size: .95rem; }
        .chat-head .who span { font-size: .78rem; color: #8696a0; }
        .chat-head .who span.typing-now { color: var(--green); }
        .chat-head .icons { margin-inline-start: auto; display: flex; gap: .8rem; color: #aebac1; }
        .chat-head .icons svg { width: 1.1rem; height: 1.1rem; }

        .chat {
            flex: 1; min-height: 0; overflow: hidden;
            display: flex; flex-direction: column; justify-content: flex-end; gap: .4rem;
            padding: .8rem .7rem;
            font-size: .86rem; line-height: 1.45;
        }

        .msg {
            position: relative; max-width: 84%; padding: .4rem .6rem .3rem;
            border-radius: .75rem; color: #e9edef; white-space: pre-line;
            box-shadow: 0 1px 1px rgb(0 0 0 / .25);
            overflow-wrap: anywhere; flex-shrink: 0;
        }
        /* RTL WhatsApp mirrors: what you send sits on the left, the bot on the right. */
        .msg--out { align-self: flex-end; background: #005c4b; border-end-end-radius: .2rem; }
        .msg--in { align-self: flex-start; background: #1f2c34; border-end-start-radius: .2rem; }
        .msg .meta {
            display: block; text-align: end; font-size: .66rem; color: rgb(233 237 239 / .62);
            margin-top: .1rem; white-space: nowrap;
        }
        .msg--out .meta .ticks { color: #53bdeb; margin-inline-start: .2rem; }
        .msg strong { font-weight: 600; }

        .msg .photo {
            display: block; width: 11rem; max-width: 100%; aspect-ratio: 4 / 3; border-radius: .5rem;
            margin: -.1rem -.3rem .35rem; position: relative; overflow: hidden;
            background: radial-gradient(circle at 70% 25%, #fff7ec, #ead6bd 55%, #d9bf9f);
        }
        .msg .photo::before {
            content: ""; position: absolute; left: 50%; bottom: 14%; width: 34%; height: 58%;
            transform: translateX(-50%);
            background: linear-gradient(90deg, #9a4a2a, #d0835a 42%, #e6a27c 52%, #a4532f);
            border-radius: 45% 45% 34% 34% / 52% 52% 26% 26%;
            box-shadow: 0 8px 10px -6px rgb(0 0 0 / .45);
        }
        .msg .photo::after {
            content: ""; position: absolute; left: 50%; bottom: 66%; width: 15%; height: 12%;
            transform: translateX(-50%);
            background: linear-gradient(90deg, #8f4325, #c97a52 50%, #94462a);
            border-radius: 3px 3px 0 0;
        }
        .msg .photo.small { width: 3.4rem; aspect-ratio: 1; margin: 0; flex: none; }

        .preview { padding: 0; overflow: hidden; }
        .preview .pv-body { padding: .5rem .65rem .35rem; }
        .preview .pv-title { display: flex; align-items: center; gap: .35rem; font-weight: 600; color: var(--accent-text); font-size: .8rem; margin-bottom: .25rem; }
        .preview .pv-row { display: flex; gap: .55rem; align-items: center; }
        .preview dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: .05rem .55rem; }
        .preview dt { color: #8696a0; }
        .preview dd { margin: 0; }
        .preview .pv-note { color: #aebac1; font-size: .78rem; margin-top: .3rem; }
        .preview .pv-btns { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid rgb(255 255 255 / .08); }
        .preview .pv-btn {
            position: relative; overflow: hidden; text-align: center; padding: .5rem; color: #53bdeb; font-weight: 600;
            transition: background-color .2s ease;
        }
        .preview .pv-btn + .pv-btn { border-inline-start: 1px solid rgb(255 255 255 / .08); }
        .preview .pv-btn.is-pressed { background: rgb(83 189 235 / .18); }
        .preview .pv-btn.is-pressed::after {
            content: ""; position: absolute; left: 50%; top: 50%; width: 2.2rem; height: 2.2rem; margin: -1.1rem 0 0 -1.1rem;
            border-radius: 50%; background: rgb(255 255 255 / .35);
            animation: tap .6s ease-out forwards;
        }
        .preview .pv-btn.is-dim { color: #607080; }

        .undo-chip {
            display: inline-block; margin-top: .3rem; padding: .1rem .55rem; border-radius: 999px;
            background: rgb(255 255 255 / .07); color: #aebac1; font-size: .74rem;
        }

        .typing { display: inline-flex; gap: .25rem; align-items: center; padding: .65rem .75rem; }
        .typing i { width: .42rem; height: .42rem; border-radius: 50%; background: #8696a0; animation: bounce 1.2s infinite ease-in-out; }
        .typing i:nth-child(2) { animation-delay: .15s; }
        .typing i:nth-child(3) { animation-delay: .3s; }

        .msg.enter { animation: pop .38s cubic-bezier(.2, .9, .3, 1.2) both; }

        .chat-input {
            flex: none; display: flex; align-items: center; gap: .5rem; padding: .5rem .6rem .9rem;
        }
        .chat-input .field { flex: 1; background: #1f2c34; color: #8696a0; border-radius: 999px; padding: .5rem .9rem; font-size: .82rem; }
        .chat-input .mic { width: 2.3rem; height: 2.3rem; border-radius: 50%; background: #00a884; display: grid; place-items: center; flex: none; }
        .chat-input .mic svg { width: 1.05rem; height: 1.05rem; color: #fff; }

        .demo-controls { display: flex; flex-direction: column; align-items: center; gap: .6rem; max-width: 34rem; }
        .demo-tabs { display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem; list-style: none; margin: 0; padding: 0; }
        .chip {
            font: inherit; font-size: .86rem; cursor: pointer;
            padding: .42rem .85rem; border-radius: 999px; border: 0;
            background: var(--surface); color: var(--muted); box-shadow: 0 0 0 1px var(--line-strong) inset;
            transition: background-color .2s ease, color .2s ease;
        }
        .chip:hover { color: var(--fg); background: rgb(255 255 255 / .07); }
        .chip[aria-pressed="true"] { background: rgb(45 212 191 / .14); color: var(--fg); box-shadow: 0 0 0 1px rgb(45 212 191 / .6) inset; }
        .chip-icon { display: inline-flex; align-items: center; gap: .35rem; }
        .chip-icon svg { width: .95rem; height: .95rem; }
        .chip-icon svg[hidden] { display: none; }
        .demo-hint { color: var(--dim); font-size: .85rem; margin: 0; text-align: center; }

        /* ---------- Sections ---------- */

        .section { padding: clamp(3.5rem, 9vw, 6.5rem) 0; position: relative; }
        .section + .section { border-top: 1px solid var(--line); }

        .eyebrow {
            display: inline-block; margin: 0 0 .8rem; font-size: .85rem; font-weight: 700;
            letter-spacing: .06em; color: var(--accent-text);
        }
        h2 { font-size: clamp(1.75rem, 5vw, 2.75rem); line-height: 1.15; letter-spacing: -.02em; margin: 0 0 .9rem; font-weight: 800; }
        h3 { font-size: 1.12rem; line-height: 1.35; margin: 0 0 .45rem; font-weight: 700; }
        .section-head { max-width: 46rem; margin-bottom: clamp(2rem, 5vw, 3rem); }
        .section-lead { color: var(--muted); margin: 0; font-size: 1.08rem; }

        .card {
            background: linear-gradient(180deg, rgb(255 255 255 / .05), rgb(255 255 255 / .02));
            border: 1px solid var(--line); border-radius: var(--radius); padding: 1.5rem;
            transition: border-color .25s ease, transform .25s ease;
        }
        .card:hover { border-color: var(--line-strong); }

        .grid { display: grid; gap: 1rem; }
        @media (min-width: 40rem) { .grid-2 { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 62rem) { .grid-3 { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 40rem) and (max-width: 61.99rem) { .grid-3 { grid-template-columns: repeat(2, 1fr); } }

        .icon {
            width: 2.75rem; height: 2.75rem; border-radius: 14px; display: grid; place-items: center;
            margin-bottom: 1rem; color: var(--accent-text);
            background: rgb(45 212 191 / .1); box-shadow: 0 0 0 1px rgb(45 212 191 / .25) inset;
        }
        .icon svg { width: 1.35rem; height: 1.35rem; }
        .card p { color: var(--muted); margin: 0; }

        /* Problem */
        .pain .card { position: relative; }
        .pain .num { font-size: 2.6rem; font-weight: 800; line-height: 1; margin-bottom: .75rem; color: transparent; -webkit-text-stroke: 1px rgb(255 255 255 / .28); }
        .pain-answer {
            margin-top: 1.5rem; display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem;
            padding: 1.1rem 1.4rem; border-radius: var(--radius);
            background: linear-gradient(120deg, rgb(37 211 102 / .12), rgb(129 140 248 / .10));
            border: 1px solid rgb(45 212 191 / .3);
            font-size: 1.1rem; font-weight: 600;
        }
        .pain-answer svg { width: 1.5rem; height: 1.5rem; color: var(--green); flex: none; }

        /* Approval flow */
        .flow { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; counter-reset: flow; }
        @media (min-width: 40rem) { .flow { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 62rem) { .flow { grid-template-columns: repeat(4, 1fr); } }
        .flow li { counter-increment: flow; display: flex; flex-direction: column; }
        .flow .step-n { font-size: .8rem; font-weight: 700; color: var(--accent-text); margin-bottom: .35rem; }
        .flow .step-n::before { content: "0" counter(flow) " · "; }
        .flow .mini {
            margin-top: auto; padding-top: 1.1rem; display: flex; flex-direction: column; gap: .35rem;
            font-size: .82rem; line-height: 1.4;
        }
        .mini .b { white-space: pre-line; padding: .4rem .6rem; border-radius: .7rem; max-width: 92%; color: #e9edef; }
        .mini .b.out { align-self: flex-end; background: #005c4b; }
        .mini .b.in { align-self: flex-start; background: #1f2c34; }
        .mini .btns { display: flex; gap: .35rem; align-self: flex-start; }
        .mini .btns span { padding: .25rem .9rem; border-radius: 999px; background: #1f2c34; color: #7fd0f3; font-weight: 600; }
        .mini .btns span.on { background: rgb(83 189 235 / .22); box-shadow: 0 0 0 1px #53bdeb inset; }

        .promise-text {
            margin-top: 1.5rem; padding: 1.4rem 1.5rem; border-radius: var(--radius);
            background: var(--surface); border: 1px solid var(--line); color: var(--muted);
        }
        .promise-text p { margin: 0; }
        .promise-text strong { color: var(--fg); }

        /* Capabilities: example requests */
        .examples-head { margin: clamp(3rem, 7vw, 4.5rem) 0 1.5rem; max-width: 46rem; }
        .examples-head h3 { font-size: clamp(1.3rem, 3.4vw, 1.7rem); letter-spacing: -.01em; }
        .tier {
            display: inline-block; font-size: .78rem; font-weight: 700; letter-spacing: .04em;
            padding: .15rem .6rem; border-radius: 999px; margin-inline-end: .4rem; vertical-align: .1em;
            color: var(--accent-text); background: rgb(45 212 191 / .1);
        }
        ul.quotes { list-style: none; margin: 1rem 0 0; padding: 0; display: grid; gap: .5rem; }
        ul.quotes li {
            position: relative; background: #0f2a26; color: #e6f4f1;
            border-radius: 14px 14px 14px 4px; padding: .55rem .85rem; font-size: .95rem;
            border: 1px solid rgb(45 212 191 / .16);
        }
        /* Decorative, and told so: the empty alt text after the slash keeps a
           screen reader from announcing "speech balloon" before every example. */
        ul.quotes li::before { content: "💬" / ""; padding-inline-end: .35rem; }

        /* How it works */
        .steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; counter-reset: step; }
        @media (min-width: 62rem) { .steps { grid-template-columns: repeat(3, 1fr); } }
        .steps li { counter-increment: step; position: relative; }
        .steps li::before {
            content: counter(step); display: grid; place-items: center;
            width: 2.6rem; height: 2.6rem; border-radius: 50%; margin-bottom: 1rem;
            background: var(--grad); color: var(--on-accent); font-weight: 800; font-size: 1.1rem;
        }
        .steps p { color: var(--muted); margin: 0; }
        .steps p + p { margin-top: .5rem; }

        /* ---------- Pricing + form ---------- */

        .buy-grid { display: grid; gap: 2rem; align-items: start; }
        @media (min-width: 62rem) {
            .buy-grid { grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); gap: 2.5rem; }
            .buy-grid .plans { position: sticky; top: 5.5rem; }
        }

        .price-card {
            position: relative; overflow: hidden;
            background: linear-gradient(180deg, rgb(255 255 255 / .06), rgb(255 255 255 / .02));
            border: 1px solid var(--line-strong); border-radius: var(--radius);
            padding: clamp(1.25rem, 3.5vw, 2rem);
        }
        .price-card::before {
            content: ""; position: absolute; inset: 0 0 auto 0; height: 3px; background: var(--grad);
        }
        .price-card + .price-card { margin-top: 1rem; }
        .price-card .name { font-weight: 700; font-size: 1.05rem; color: var(--muted); margin: 0; }
        .price-card .big { font-size: clamp(2rem, 6vw, 2.75rem); font-weight: 800; line-height: 1.15; letter-spacing: -.02em; margin: .3rem 0 0; }
        .price-card .vat { color: var(--muted); font-size: 1rem; font-weight: 400; letter-spacing: 0; white-space: nowrap; }

        dl.includes { margin: 1.4rem 0 0; display: grid; gap: .8rem; padding-top: 1.25rem; border-top: 1px solid var(--line); }
        /* The tick lives INSIDE the dt rather than beside it, because a dl may
           only hold dt and dd — a span between them is markup a screen reader is
           entitled to ignore. */
        dl.includes div { display: flex; flex-wrap: wrap; gap: 0 .4rem; align-items: baseline; }
        dl.includes dt { font-weight: 600; min-width: 0; }
        dl.includes dd { margin: 0; color: var(--muted); flex: 1 1 14rem; font-size: .95rem; }
        dl.includes .tick { color: var(--ok); }

        .note { color: var(--muted); font-size: .92rem; }

        .form-card {
            background: var(--surface-2); border: 1px solid var(--line-strong); border-radius: var(--radius);
            padding: clamp(1.25rem, 4vw, 2.25rem);
            box-shadow: 0 30px 80px -40px rgb(0 0 0 / .7);
        }
        .form-card h2 { font-size: clamp(1.5rem, 4vw, 2rem); }

        form { margin-top: 1.25rem; }
        fieldset { border: 0; margin: 0 0 1.5rem; padding: 0; min-width: 0; }
        legend { font-weight: 700; font-size: 1.05rem; padding: 0; margin-bottom: .4rem; }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        .req { color: var(--accent-text); }

        input[type=text], input[type=email], input[type=tel], input[type=url] {
            width: 100%; padding: .8rem .9rem; border-radius: 12px; min-height: 3rem;
            border: 1px solid var(--field-line); background: var(--field); color: var(--fg); font: inherit;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        input::placeholder { color: var(--dim); opacity: 1; }
        input[type=text]:hover, input[type=email]:hover, input[type=tel]:hover { border-color: #8d97ad; }
        input[type=text]:focus-visible, input[type=email]:focus-visible, input[type=tel]:focus-visible {
            border-color: var(--teal); box-shadow: 0 0 0 4px rgb(45 212 191 / .18);
        }
        input[aria-invalid=true] { border-color: var(--error); border-width: 2px; }
        input[type=radio], input[type=checkbox] { accent-color: var(--green); }

        .hint { color: var(--muted); font-size: .9rem; margin: .35rem 0 0; }
        .error { color: var(--error); font-size: .92rem; margin: .35rem 0 0; font-weight: 600; }

        .opt {
            display: flex; gap: .75rem; align-items: flex-start; border: 1px solid var(--field-line);
            border-radius: var(--radius-sm); padding: 1rem 1.1rem; margin: 0 0 .6rem; cursor: pointer; font-weight: 400;
            background: var(--field); transition: border-color .2s ease, background-color .2s ease;
        }
        .opt:hover { border-color: var(--teal); }
        .opt input { margin-top: .3rem; width: 1.2rem; height: 1.2rem; flex: none; }
        .opt:has(input:checked) { border-color: var(--teal); background: rgb(45 212 191 / .08); box-shadow: 0 0 0 1px var(--teal) inset; }
        .opt:has(input:focus-visible) { outline: 3px solid var(--focus); outline-offset: 2px; }
        .opt .body { display: flex; flex-direction: column; gap: .15rem; min-width: 0; }
        .opt .title { font-weight: 700; }
        .opt .meta { color: var(--muted); font-size: .9rem; }

        .extras { display: grid; gap: .6rem; }
        .extras .row { display: grid; gap: .25rem; }
        .extras label { margin: 0; font-weight: 500; font-size: .92rem; color: var(--muted); }

        .total {
            background: linear-gradient(120deg, rgb(37 211 102 / .1), rgb(129 140 248 / .08));
            border: 1px solid rgb(45 212 191 / .4);
            border-radius: var(--radius-sm); padding: 1rem 1.2rem; margin: 0 0 1.5rem;
        }
        .total .figure { font-size: 1.35rem; font-weight: 800; }

        .check { display: flex; gap: .65rem; align-items: flex-start; margin: 1.25rem 0; }
        .check input { margin-top: .3rem; width: 1.2rem; height: 1.2rem; flex: none; }
        .check label { margin: 0; font-weight: 400; color: var(--muted); }

        button[type=submit] { width: 100%; padding: 1.05rem 1rem; font-size: 1.08rem; }

        .secure { display: flex; align-items: center; justify-content: center; gap: .45rem; color: var(--muted); font-size: .88rem; margin: .9rem 0 0; text-align: center; }
        .secure svg { width: 1rem; height: 1rem; flex: none; }

        .errors {
            background: rgb(255 157 157 / .1); border: 1px solid var(--error);
            border-radius: var(--radius-sm); padding: .9rem 1.1rem; margin-bottom: 1.25rem;
        }
        .errors ul { margin: .35rem 0 0; padding-inline-start: 1.1rem; }

        /* ---------- FAQ ---------- */

        .faq { display: grid; gap: .65rem; max-width: 52rem; }
        .faq details {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-sm);
            transition: border-color .2s ease, background-color .2s ease;
        }
        .faq details[open] { border-color: rgb(45 212 191 / .45); background: rgb(255 255 255 / .05); }
        .faq summary {
            cursor: pointer; font-weight: 600; list-style: none; padding: 1.05rem 1.2rem;
            display: flex; align-items: center; gap: 1rem; border-radius: var(--radius-sm);
        }
        .faq summary::-webkit-details-marker { display: none; }
        .faq summary::after {
            content: ""; margin-inline-start: auto; flex: none; width: .6rem; height: .6rem;
            border-inline-end: 2px solid var(--accent-text); border-bottom: 2px solid var(--accent-text);
            transform: rotate(45deg); transition: transform .25s ease; margin-top: -.25rem;
        }
        .faq details[open] summary::after { transform: rotate(225deg); margin-top: .25rem; }
        .faq p { color: var(--muted); margin: 0; padding: 0 1.2rem 1.15rem; }

        /* ---------- Final CTA + footer ---------- */

        .cta-band {
            text-align: center; padding: clamp(2rem, 6vw, 3.5rem) 1.25rem; border-radius: calc(var(--radius) + 6px);
            background:
                radial-gradient(30rem 14rem at 50% 0%, rgb(45 212 191 / .22), transparent 70%),
                linear-gradient(180deg, rgb(255 255 255 / .05), rgb(255 255 255 / .02));
            border: 1px solid var(--line-strong);
        }
        .cta-band h2 { margin-bottom: .6rem; }
        .cta-band p { color: var(--muted); margin: 0 auto 1.5rem; max-width: 34rem; }

        .site-footer { border-top: 1px solid var(--line); padding: 2.5rem 0 3rem; color: var(--muted); font-size: .92rem; }
        .site-footer .wrap { display: grid; gap: 1rem; text-align: center; justify-items: center; }
        .site-footer p { margin: 0; }
        .site-footer nav ul { list-style: none; display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem 1.25rem; margin: 0; padding: 0; }

        /* ---------- Motion ---------- */

        .js .reveal { opacity: 0; transform: translateY(18px); transition: opacity .7s ease, transform .7s cubic-bezier(.2, .7, .2, 1); }
        .js .reveal.is-in { opacity: 1; transform: none; }

        @keyframes pop { from { opacity: 0; transform: translateY(10px) scale(.96); } to { opacity: 1; transform: none; } }
        @keyframes bounce { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-4px); opacity: 1; } }
        @keyframes tap { from { transform: scale(.2); opacity: .9; } to { transform: scale(2.4); opacity: 0; } }

        .demo.is-paused .typing i { animation-play-state: paused; }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition: none !important; animation: none !important; }
            .js .reveal { opacity: 1; transform: none; }
            .preview .pv-btn.is-pressed::after { display: none; }
        }
    </style>
</head>
<body>

<a class="skip" href="#main">דילוג לתוכן הראשי</a>

<header class="site-header">
    <div class="wrap">
        <a class="brand" href="#top">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="#04140c" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12Z"/><path d="m8.5 12 2.3 2.3 4.7-4.6"/></svg>
            </span>
            בוט ניהול האתר
        </a>

        <nav class="nav" aria-label="ניווט בעמוד">
            <ul>
                <li><a href="#demo-title">הדגמה</a></li>
                <li><a href="#features">יכולות</a></li>
                <li><a href="#how">איך זה עובד</a></li>
                <li><a href="#price-title">מחיר</a></li>
                <li><a href="#faq-title">שאלות</a></li>
            </ul>
        </nav>

        <a class="btn btn-primary btn-sm" href="#buy">{{ $allHaveTrial ? 'לנסות בחינם' : 'הרשמה' }}</a>
    </div>
</header>

<main id="main">

    {{-- ====================== Hero ====================== --}}

    <section class="hero" id="top" aria-labelledby="hero-title">
        <div class="wrap hero-grid">
            <div>
                @if ($allHaveTrial)
                    <p class="badge"><span class="dot" aria-hidden="true"></span> {{ $headline->trial_days }} ימים ניסיון חינם</p>
                @endif

                <h1 id="hero-title">האתר שלכם <span class="grad-text">מנוהל מוואטסאפ</span></h1>
                <p class="lead">
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

                <div class="hero-actions">
                    <a class="btn btn-primary" href="#buy">מתחילים — {{ $allHaveTrial ? $headline->trial_days.' ימים בחינם' : 'רכישה' }}</a>
                    <a class="btn btn-ghost" href="#features">מה הוא יודע לעשות</a>
                </div>

                <ul class="trust">
                    <li><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>כל שינוי רק אחרי "כן" שלכם</li>
                    <li><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>עונה מנתוני האתר בזמן אמת</li>
                    <li><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>התקנה בכ־5 דקות, או שנתקין עבורכם</li>
                </ul>
            </div>

            {{-- The demo. The phone itself is decorative and hidden from assistive
                 technology — a scripted conversation re-rendering every second
                 would be noise in a screen reader — and the hidden paragraph says
                 in words what it shows. The controls sit outside the hidden part so
                 they stay reachable; the pause button is what WCAG 2.2.2 asks of
                 anything that moves on its own for more than five seconds. --}}
            <div class="demo" id="demo">
                <h2 id="demo-title" class="sr-only">הדגמה: ככה נראית שיחה עם הבוט</h2>
                <p class="sr-only">
                    הדגמה מונפשת של שיחת וואטסאפ עם הבוט, בארבעה תרחישים.
                    מכירות: שואלים "כמה הזמנות היו היום?", הבוט עונה עם מספר ההזמנות, סכום המכירות וההזמנות שממתינות לתשלום, מציע לסמן אותן כהושלמו, ואחרי "כן" מאשר שבוצע.
                    מוצר מתמונה: שולחים תמונה עם "תעלה מוצר חדש: כד קרמיקה 120 ש״ח", הבוט מציג תצוגה מקדימה של המוצר, ואחרי "כן" המוצר מתפרסם באתר.
                    לידים: מבקשים "תודיע לי על כל ליד חדש", ומאותו רגע כל ליד מהטופס מגיע כהודעה עם השם, הטלפון והפנייה.
                    תוספים: מבקשים "תעדכן את כל התוספים", הבוט מציג אילו תוספים יתעדכנו, ואחרי "כן" מעדכן ובודק שהאתר עולה.
                </p>

                <div class="phone" aria-hidden="true">
                    <div class="screen">
                        <div class="chat-head">
                            <span class="avatar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#04140c" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12Z"/><path d="m8.5 12 2.3 2.3 4.7-4.6"/></svg>
                            </span>
                            <span class="who">
                                <b>בוט ניהול האתר</b>
                                <span data-status>מחובר</span>
                            </span>
                            <span class="icons">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="m15.5 10.5 6-3.5v10l-6-3.5"/></svg>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                            </span>
                        </div>

                        {{-- Without script, the first scenario's finished conversation
                             is what is shown; the script replaces it with the
                             animated version. --}}
                        <div class="chat" data-chat>
                            <div class="msg msg--out">כמה הזמנות היו היום?<span class="meta">09:41<span class="ticks">✓✓</span></span></div>
                            <div class="msg msg--in">📦 היום עד עכשיו: 12 הזמנות · ₪3,480
הכי נמכר: כד קרמיקה (4 יח׳)
2 הזמנות ממתינות לתשלום בהעברה<span class="meta">09:41</span></div>
                            <div class="msg msg--out">שתיהן שילמו, תסמן כהושלמו<span class="meta">09:42<span class="ticks">✓✓</span></span></div>
                            <div class="msg msg--in">✅ בוצע. 2 הזמנות סומנו כהושלמו.
<span class="undo-chip">לביטול כתבו "בטל"</span><span class="meta">09:42</span></div>
                        </div>

                        <div class="chat-input">
                            <span class="field">הודעה</span>
                            <span class="mic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></span>
                        </div>
                    </div>
                </div>

                <div class="demo-controls" data-controls hidden>
                    <ul class="demo-tabs" aria-label="תרחישי ההדגמה">
                        <li><button type="button" class="chip" data-scenario="0" aria-pressed="true">מכירות היום</button></li>
                        <li><button type="button" class="chip" data-scenario="1" aria-pressed="false">מוצר מתמונה</button></li>
                        <li><button type="button" class="chip" data-scenario="2" aria-pressed="false">התראת ליד</button></li>
                        <li><button type="button" class="chip" data-scenario="3" aria-pressed="false">עדכון תוספים</button></li>
                    </ul>
                    <button type="button" class="chip chip-icon" data-toggle>
                        <svg data-icon-pause aria-hidden="true" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                        <svg data-icon-play aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" hidden><path d="M17 6.5v11L7.5 12z"/></svg>
                        <span data-toggle-label>השהיית ההדגמה</span>
                    </button>
                </div>
                <p class="demo-hint">הדגמה. הנתונים בה לדוגמה בלבד.</p>
            </div>
        </div>
    </section>

    {{-- ====================== The problem ====================== --}}

    <section class="section" aria-labelledby="pain-title">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="eyebrow">הבעיה</p>
                <h2 id="pain-title">יותר עבודה מזמן</h2>
                <p class="section-lead">האתר הוא חלק מהעסק, אבל כל שינוי קטן בו הופך למשימה. ומה שקורה בו — נשאר מאחורי מסך כניסה.</p>
            </div>

            <div class="grid grid-3 pain">
                <div class="card reveal">
                    <div class="num" aria-hidden="true">01</div>
                    <h3>שינוי של דקה, חצי שעה של עבודה</h3>
                    <p>להתחבר לוורדפרס, למצוא את המוצר, לזכור איפה הכפתור — כדי לעדכן מחיר אחד.</p>
                </div>
                <div class="card reveal">
                    <div class="num" aria-hidden="true">02</div>
                    <h3>מחכים למישהו אחר</h3>
                    <p>טלפון בעמוד צור קשר, טקסט בבאנר, מוצר חדש — וכל אחד מהם מחכה למפתח שיתפנה.</p>
                </div>
                <div class="card reveal">
                    <div class="num" aria-hidden="true">03</div>
                    <h3>לא יודעים מה קורה באתר</h3>
                    <p>כמה נמכר היום? מי השאיר פרטים? יש תוסף שצריך עדכון? כל תשובה היא עוד מסך לחפש בו.</p>
                </div>
            </div>

            <p class="pain-answer reveal">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12Z"/></svg>
                עם הבוט — כותבים לו כמו לעובד, והוא עושה את זה מהאתר עצמו.
            </p>
        </div>
    </section>

    {{-- ====================== The approval flow ====================== --}}

    {{-- The sentence that decides whether somebody trusts this at all. An agent
         that can edit a business's website has to be one the owner approves each
         change on, and saying so before the price is the difference between a
         product and a risk. --}}

    <section class="section" aria-labelledby="promise-title">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="eyebrow">ככה זה עובד בצ׳אט</p>
                <h2 id="promise-title">שום דבר לא קורה בלי "כן" שלכם</h2>
                <p class="section-lead">כל בקשה לשינוי עוברת את אותם ארבעה צעדים. אתם רואים בדיוק מה ישתנה — לפני שזה משתנה.</p>
            </div>

            <ol class="flow">
                <li class="card reveal">
                    <span class="step-n">בקשה</span>
                    <h3>כותבים מה צריך</h3>
                    <p>בעברית רגילה, כמו לעובד. אפשר גם לצרף תמונה.</p>
                    <div class="mini" aria-hidden="true">
                        <span class="b out">תעדכן את המחיר של הכורסה ל־1,290 ש״ח</span>
                    </div>
                </li>
                <li class="card reveal">
                    <span class="step-n">תצוגה מקדימה</span>
                    <h3>רואים מה ישתנה</h3>
                    <p>התצוגה נבנית מהנתונים שהאתר החזיר — המוצר האמיתי, המחיר האמיתי.</p>
                    <div class="mini" aria-hidden="true">
                        <span class="b in">כורסת קטיפה אפורה
₪1,490 ← ₪1,290</span>
                    </div>
                </li>
                <li class="card reveal">
                    <span class="step-n">אישור</span>
                    <h3>לוחצים "כן"</h3>
                    <p>או "לא", או פשוט מקלידים. בלי אישור — לא קורה כלום.</p>
                    <div class="mini" aria-hidden="true">
                        <span class="btns"><span class="on">כן</span><span>לא</span></span>
                    </div>
                </li>
                <li class="card reveal">
                    <span class="step-n">ביצוע</span>
                    <h3>בוצע — ואפשר "בטל"</h3>
                    <p>התחרטתם? לרוב השינויים מספיק לכתוב "בטל".</p>
                    <div class="mini" aria-hidden="true">
                        <span class="b in">✅ בוצע. המחיר עודכן באתר.</span>
                    </div>
                </li>
            </ol>

            <div class="promise-text reveal">
                <p>
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
        </div>
    </section>

    {{-- ====================== What it can do ====================== --}}

    <section class="section" id="features" aria-labelledby="can-do">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="eyebrow">יכולות</p>
                <h2 id="can-do">מה אפשר לבקש ממנו</h2>
                <p class="section-lead">
                    לא תפריט של שלוש פעולות. זה עוזר שקורא מהאתר בזמן אמת — הזמנות, לידים, מוצרים, מנויים, תגובות,
                    תפריטים, קטגוריות, אזורי משלוח, תוספים ויומן השגיאות — עונה, ומציע שינוי שמתבצע רק אחרי שאישרתם.
                </p>
            </div>

            <div class="grid grid-3">
                <div class="card reveal">
                    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/></svg></div>
                    <h3>תשובות מהנתונים האמיתיים</h3>
                    <p>הזמנות, מכירות, לידים ומוצרים — הבוט שואל את האתר ועונה במספרים, לא בניחושים.</p>
                </div>
                <div class="card reveal">
                    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></div>
                    <h3>שינויים בתצוגה מקדימה</h3>
                    <p>טקסטים, מחירים, מלאי, תפריטים, קופונים — מוצגים לפני הביצוע, עם "כן"/"לא" ו"בטל".</p>
                </div>
                <div class="card reveal">
                    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l2-3h6l2 3h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg></div>
                    <h3>מוצר חדש מתמונה</h3>
                    <p>מצלמים, כותבים שם ומחיר — והמוצר מוכן לפרסום בחנות.</p>
                </div>
                <div class="card reveal">
                    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M7.5 13.5h3M7.5 17h6"/></svg></div>
                    <h3>דוחות יומיים ושבועיים</h3>
                    <p>"כל בוקר בשמונה" — המכירות של אתמול והלידים החדשים מחכים לכם בוואטסאפ.</p>
                </div>
                <div class="card reveal">
                    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg></div>
                    <h3>התראה על כל ליד חדש</h3>
                    <p>מישהו השאיר פרטים בטופס? ההודעה אצלכם תוך רגע — עם השם, הטלפון והפנייה.</p>
                </div>
                <div class="card reveal">
                    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg></div>
                    <h3>עדכוני תוספים</h3>
                    <p>מעדכן תוספים ותבניות באישורכם, ואחרי כל עדכון בודק שהאתר עדיין עולה.</p>
                </div>
            </div>

            <div class="examples-head reveal">
                <h3>דוגמאות למה שאפשר לכתוב לו</h3>
                <p class="section-lead">מהפשוט ועד מה שבאמת חוסך שעות.</p>
            </div>

            <div class="grid grid-3">
                <div class="card reveal">
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

                <div class="card reveal">
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

                <div class="card reveal">
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
        </div>
    </section>

    {{-- ====================== How it works ====================== --}}

    <section class="section" aria-labelledby="how">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="eyebrow">התחלה</p>
                <h2 id="how">איך זה עובד</h2>
                <p class="section-lead">שלושה צעדים, ורובם קורים פעם אחת.</p>
            </div>

            <ol class="steps">
                <li class="card reveal">
                    <h3>נרשמים כאן</h3>
                    <p>ומזינים את מספר הוואטסאפ שינהל את האתר.</p>
                </li>
                <li class="card reveal">
                    <h3>מתקינים תוסף</h3>
                    <p>באתר הוורדפרס — כ־5 דקות, או שנתקין עבורכם ללא תשלום.</p>
                </li>
                <li class="card reveal">
                    <h3>מאמתים את המספר — וכותבים לו</h3>
                    <p>בקוד בן 6 ספרות שמגיע בוואטסאפ מהמספר של הבוט.</p>
                    <p>מכאן זו שיחה רגילה.</p>
                </li>
            </ol>
        </div>
    </section>

    {{-- ====================== Pricing + the form ====================== --}}

    <div class="section">
        <div class="wrap buy-grid">

            <section class="plans" aria-labelledby="price-title">
                <p class="eyebrow">מחיר</p>
                <h2 id="price-title">{{ $single ? 'המחיר' : 'המסלולים' }}</h2>
                <p class="section-lead" style="margin-bottom:1.5rem">כל המחירים בעמוד זה הם לפני מע״מ.</p>

                {{-- A card per plan, each stating ITS OWN terms. The trial, the price of
                     an extra number and the per-message charge are what a buyer is
                     actually agreeing to, and they differ between plans — printing the
                     first plan's set above a list the buyer can choose from is how
                     somebody buys a usage charge they were never shown. --}}
                @foreach ($plans as $plan)
                <div class="price-card">
                    <p class="name">{{ $plan->name }}</p>
                    <p class="big">
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

                        @if ($plan->billsWritings())
                            <div>
                                <dt><span class="tick" aria-hidden="true">✓</span> כתיבת תוכן:</dt>
                                <dd>
                                    טקסט של יותר מ־{{ (int) config('siteagent.writing.min_words', 300) }} מילים שהבוט כותב בשבילכם — פוסט, קטע בעמוד או תיאור מוצר —
                                    @if ((int) $plan->included_writings > 0)
                                        {{ number_format($plan->included_writings) }} בחודש כלולים במחיר, ומעבר להם {{ $plan->writingNetLabel() }} לטקסט.
                                    @else
                                        {{ $plan->writingNetLabel() }} לטקסט.
                                    @endif
                                    כל טיוטה נספרת, גם אם בחרתם שלא לפרסם אותה; טקסט שכתבתם בעצמכם וביקשתם רק להעלות — לא נספר. הבוט מציין את זה ליד כל טיוטה.
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

            <section id="buy" class="form-card" aria-labelledby="buy-title">
                <h2 id="buy-title">הרשמה</h2>
                <p class="section-lead">
                    @if ($allHaveTrial)
                        מזינים כרטיס, לא מחויבים היום, ואפשר לבטל בתוך {{ $headline->trial_days }} הימים.
                    @else
                        התשלום מתבצע בעמוד מאובטח של חברת הסליקה.
                    @endif
                </p>

                @if ($errors->any())
                    <div class="errors" role="alert" style="margin-top:1.25rem">
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
                        <input id="email" name="email" type="email" required autocomplete="email" inputmode="email" dir="ltr"
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

                    <button type="submit" class="btn btn-primary">
                        {{ $allHaveTrial ? 'מתחילים את תקופת הניסיון' : 'מעבר לתשלום מאובטח' }}
                    </button>
                    <p class="secure">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg>
                        פרטי האשראי מוזנים בעמוד המאובטח של חברת הסליקה בלבד.
                    </p>
                </form>
            </section>
        </div>
    </div>

    {{-- ====================== FAQ ====================== --}}

    <section class="section" aria-labelledby="faq-title">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="eyebrow">שאלות</p>
                <h2 id="faq-title">שאלות נפוצות</h2>
            </div>

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
                        לכן תמליל השיחה נשמר אצלנו לזמן מוגבל בכוונה — {{ max(1, (int) config('siteagent.assistant.transcript_days', 7)) }} ימים — ונמחק אחר כך,
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
        </div>
    </section>

    <section class="section" aria-labelledby="final-title">
        <div class="wrap">
            <div class="cta-band reveal">
                <h2 id="final-title">הודעה אחת — והאתר מתעדכן</h2>
                <p>בלי להיכנס לוורדפרס, בלי לחכות לאף אחד, ושום דבר לא משתנה בלי "כן" שלכם.</p>
                <a class="btn btn-primary" href="#buy">מתחילים — {{ $allHaveTrial ? $headline->trial_days.' ימים בחינם' : 'רכישה' }}</a>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="wrap">
        <a class="brand" href="#top" style="margin:0">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="#04140c" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12Z"/><path d="m8.5 12 2.3 2.3 4.7-4.6"/></svg>
            </span>
            בוט ניהול האתר
        </a>
        <p>התשלום מתבצע בעמוד מאובטח של חברת הסליקה. פרטי האשראי אינם נשמרים אצלנו.</p>
        <p>יש שאלה? <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a></p>
        <nav aria-label="מסמכים משפטיים">
            <ul>
                <li><a href="{{ route('legal.terms') }}">תנאי שימוש</a></li>
                <li><a href="{{ route('legal.privacy') }}">מדיניות פרטיות</a></li>
            </ul>
        </nav>
    </div>
</footer>

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

    // Sections fade in as they reach the viewport. Without IntersectionObserver
    // (or with reduced motion, where the CSS already shows them) everything is
    // simply visible.
    (function () {
        var items = document.querySelectorAll('.reveal');

        if (!('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('is-in'); });
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

        items.forEach(function (el) { io.observe(el); });
    })();

    /*
     | The hero demo: a scripted WhatsApp conversation, one scenario at a time.
     |
     | Decorative by design (the phone is aria-hidden and a visually hidden
     | paragraph carries the words), so nothing here announces anything. Motion rules:
     |  - prefers-reduced-motion: never auto-plays; each scenario is shown in its
     |    finished state, and the tabs switch between them.
     |  - the pause button stops it in place (WCAG 2.2.2), and play resumes from
     |    the same step.
     |  - off screen or in a background tab it waits, so it is not burning frames
     |    nobody is looking at.
     | All text goes in through textContent — the script data is ours, but
     | building markup from strings is the habit this page does not have.
     */
    (function () {
        var demo = document.getElementById('demo');

        if (!demo) { return; }

        var chat = demo.querySelector('[data-chat]');
        var status = demo.querySelector('[data-status]');
        var controls = demo.querySelector('[data-controls]');
        var tabs = demo.querySelectorAll('[data-scenario]');
        var toggle = demo.querySelector('[data-toggle]');
        var toggleLabel = demo.querySelector('[data-toggle-label]');
        var iconPause = demo.querySelector('[data-icon-pause]');
        var iconPlay = demo.querySelector('[data-icon-play]');

        if (!chat || !controls || !toggle) { return; }

        var SCENARIOS = [
            [
                { t: 'out', text: 'כמה הזמנות היו היום?' },
                { t: 'typing', ms: 1300 },
                { t: 'in', text: '📦 היום עד עכשיו: 12 הזמנות · ₪3,480\nהכי נמכר: כד קרמיקה (4 יח׳)\n2 הזמנות ממתינות לתשלום בהעברה' },
                { t: 'out', text: 'שתיהן שילמו, תסמן כהושלמו' },
                { t: 'typing', ms: 1100 },
                { t: 'preview', title: 'לפני שאני מבצע:', rows: [['#1043', 'רונית לוי · ₪240'], ['#1047', 'אבי כהן · ₪610'], ['סטטוס', 'ממתינה ← הושלמה']], note: 'לאשר?' },
                { t: 'tap' },
                { t: 'typing', ms: 900 },
                { t: 'done', text: '✅ בוצע. 2 הזמנות סומנו כהושלמו.' },
            ],
            [
                { t: 'photo', text: 'תעלה מוצר חדש: כד קרמיקה 120 ש״ח' },
                { t: 'typing', ms: 1500 },
                { t: 'preview', title: 'מוצר חדש — תצוגה מקדימה', photo: true, rows: [['שם', 'כד קרמיקה'], ['מחיר', '₪120'], ['קטגוריה', 'כלי בית']], note: 'לפרסם באתר?' },
                { t: 'tap' },
                { t: 'typing', ms: 1000 },
                { t: 'done', text: '✅ פורסם. המוצר "כד קרמיקה" עלה לחנות.' },
            ],
            [
                { t: 'out', text: 'תודיע לי על כל ליד חדש' },
                { t: 'typing', ms: 1100 },
                { t: 'in', text: 'סגור 👍 מעכשיו כל ליד מטופס "צור קשר" יגיע אליך לכאן.' },
                { t: 'pause', ms: 1200 },
                { t: 'in', text: '🔔 ליד חדש מהאתר\nשם: מיכל אברהם\nטלפון: 050-1234567\n"מעוניינת בהצעת מחיר לאירוע של 40 איש"' },
                { t: 'out', text: 'מעולה, תודה!' },
            ],
            [
                { t: 'out', text: 'תעדכן את כל התוספים' },
                { t: 'typing', ms: 1400 },
                { t: 'preview', title: '3 תוספים עם עדכון:', rows: [['WooCommerce', '9.3 ← 9.4'], ['Elementor', '3.24 ← 3.25'], ['Yoast SEO', '23.5 ← 23.6']], note: 'אחרי כל עדכון אבדוק שהאתר עולה. לעדכן?' },
                { t: 'tap' },
                { t: 'typing', ms: 1700 },
                { t: 'done', text: '✅ בוצע. 3 תוספים עודכנו, והאתר עולה תקין.' },
            ],
        ];

        // How long each kind of step stays before the next one — tuned so a
        // reply can actually be read before the conversation moves on.
        var DWELL = { out: 900, photo: 1100, in: 2000, preview: 2200, tap: 700, done: 3600, pause: 0 };

        var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
        var current = 0;
        var step = 0;
        var clock = 0;
        var timer = null;
        var playing = !reduce.matches;
        var visible = true;
        var onScreen = true;

        function el(tag, cls, text) {
            var node = document.createElement(tag);
            if (cls) { node.className = cls; }
            if (text) { node.textContent = text; }
            return node;
        }

        function time() {
            var minutes = 41 + clock;
            clock += 1;
            return '09:' + (minutes < 10 ? '0' : '') + minutes;
        }

        function meta(outgoing) {
            var m = el('span', 'meta', time());
            if (outgoing) { m.appendChild(el('span', 'ticks', '✓✓')); }
            return m;
        }

        function bubble(kind, text, animate) {
            var node = el('div', 'msg msg--' + kind + (animate ? ' enter' : ''));
            node.appendChild(document.createTextNode(text));
            node.appendChild(meta(kind === 'out'));
            return node;
        }

        function photoBubble(text, animate) {
            var node = el('div', 'msg msg--out' + (animate ? ' enter' : ''));
            node.appendChild(el('span', 'photo'));
            node.appendChild(document.createTextNode(text));
            node.appendChild(meta(true));
            return node;
        }

        function previewBubble(s, animate) {
            var node = el('div', 'msg msg--in preview' + (animate ? ' enter' : ''));
            var body = el('div', 'pv-body');
            body.appendChild(el('div', 'pv-title', s.title));

            var dl = el('dl');
            s.rows.forEach(function (row) {
                dl.appendChild(el('dt', '', row[0]));
                dl.appendChild(el('dd', '', row[1]));
            });

            if (s.photo) {
                var line = el('div', 'pv-row');
                line.appendChild(el('span', 'photo small'));
                line.appendChild(dl);
                body.appendChild(line);
            } else {
                body.appendChild(dl);
            }

            if (s.note) { body.appendChild(el('div', 'pv-note', s.note)); }
            body.appendChild(meta(false));
            node.appendChild(body);

            var btns = el('div', 'pv-btns');
            btns.appendChild(el('span', 'pv-btn', 'כן'));
            btns.appendChild(el('span', 'pv-btn', 'לא'));
            node.appendChild(btns);
            return node;
        }

        function doneBubble(text, animate) {
            var node = el('div', 'msg msg--in' + (animate ? ' enter' : ''));
            node.appendChild(document.createTextNode(text + '\n'));
            node.appendChild(el('span', 'undo-chip', 'לביטול כתבו "בטל"'));
            node.appendChild(meta(false));
            return node;
        }

        function setTyping(on) {
            var existing = chat.querySelector('.typing');
            if (existing) { existing.remove(); }
            if (status) {
                status.textContent = on ? 'מקליד/ה…' : 'מחובר';
                status.classList.toggle('typing-now', on);
            }
            if (on) {
                var dots = el('div', 'msg msg--in typing enter');
                dots.appendChild(el('i'));
                dots.appendChild(el('i'));
                dots.appendChild(el('i'));
                chat.appendChild(dots);
            }
        }

        // Applies one step. `animate` is false when a scenario is drawn in its
        // finished state, which skips the typing dots and plays no entrances.
        function apply(s, animate) {
            if (s.t !== 'typing' && s.t !== 'pause') { setTyping(false); }

            switch (s.t) {
                case 'typing':
                    if (animate) { setTyping(true); }
                    return s.ms;
                case 'pause':
                    return s.ms;
                case 'out':
                case 'in':
                    chat.appendChild(bubble(s.t, s.text, animate));
                    break;
                case 'photo':
                    chat.appendChild(photoBubble(s.text, animate));
                    break;
                case 'preview':
                    chat.appendChild(previewBubble(s, animate));
                    break;
                case 'tap':
                    var previews = chat.querySelectorAll('.preview');
                    var last = previews[previews.length - 1];
                    if (last) {
                        var b = last.querySelectorAll('.pv-btn');
                        b[0].classList.add('is-pressed');
                        b[1].classList.add('is-dim');
                    }
                    chat.appendChild(bubble('out', 'כן', animate));
                    break;
                case 'done':
                    chat.appendChild(doneBubble(s.text, animate));
                    break;
            }

            return DWELL[s.t];
        }

        function reset() {
            clearTimeout(timer);
            timer = null;
            chat.textContent = '';
            step = 0;
            clock = 0;
            setTyping(false);
        }

        function drawFinished(index) {
            reset();
            SCENARIOS[index].forEach(function (s) { apply(s, false); });
            step = SCENARIOS[index].length;
        }

        function active() { return playing && visible && onScreen; }

        function tick() {
            timer = null;
            if (!active()) { return; }

            var steps = SCENARIOS[current];

            if (step >= steps.length) {
                select((current + 1) % SCENARIOS.length);
                return;
            }

            var wait = apply(steps[step], true);
            step += 1;
            timer = setTimeout(tick, wait);
        }

        function resume() {
            if (timer === null && active()) { timer = setTimeout(tick, 350); }
        }

        function select(index) {
            current = index;
            tabs.forEach(function (tab) {
                tab.setAttribute('aria-pressed', String(Number(tab.dataset.scenario) === index));
            });

            if (playing) {
                reset();
                resume();
            } else {
                drawFinished(index);
            }
        }

        function setPlaying(on) {
            playing = on;
            demo.classList.toggle('is-paused', !on);
            toggleLabel.textContent = on ? 'השהיית ההדגמה' : 'הפעלת ההדגמה';
            // SVG elements have no `hidden` property, only the attribute.
            iconPause.toggleAttribute('hidden', !on);
            iconPlay.toggleAttribute('hidden', on);

            if (on) {
                // A finished scenario restarts; one stopped midway carries on.
                if (step >= SCENARIOS[current].length) { reset(); }
                resume();
            } else {
                clearTimeout(timer);
                timer = null;
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () { select(Number(tab.dataset.scenario)); });
        });

        toggle.addEventListener('click', function () { setPlaying(!playing); });

        document.addEventListener('visibilitychange', function () {
            visible = !document.hidden;
            if (visible) { resume(); } else { clearTimeout(timer); timer = null; }
        });

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                onScreen = entries[0].isIntersecting;
                if (onScreen) { resume(); } else { clearTimeout(timer); timer = null; }
            }, { threshold: 0.2 }).observe(demo);
        }

        controls.hidden = false;
        setPlaying(playing);
        select(0);
    })();
</script>
</body>
</html>
