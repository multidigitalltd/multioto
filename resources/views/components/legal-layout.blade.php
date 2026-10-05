{{--
    המעטפת של שני המסמכים המשפטיים.

    עמוד משפטי נקרא פעם אחת, לרוב מהטלפון, ולרוב כשמישהו כבר עצר באמצע רכישה
    כדי לבדוק משהו. לכן: טקסט בלבד, בלי סקריפטים, בלי טעינות חיצוניות, ועם
    תוכן עניינים שמאפשר לקפוץ לסעיף אחד במקום לגלול הכול.

    נגישות (ת"י 5568 / WCAG 2.2 AA): HTML סמנטי, היררכיית כותרות אמיתית,
    דילוג לתוכן, ניגודיות, focus גלוי, ו-prefers-reduced-motion מכובד.
--}}
@props(['title', 'description'])
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ config('legal.company.name') }}</title>
    <meta name="description" content="{{ $description }}">
    {{-- נדרש שיהיה קריא לכל מי שמגיע: גם למטא, שטוענת את העמוד כדי לאשר את האפליקציה. --}}
    <meta name="robots" content="index,follow">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #2b2b30; --card: #34343a; --fg: #f4f5f7; --muted: #b4bac4;
            --border: #4a4a52; --brand: #ec4899; --link: #ffa8d4;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.75; display: flex; justify-content: center; align-items: flex-start;
            padding: 2rem 1rem;
        }
        main {
            background: var(--card); width: 100%; max-width: 48rem; border-radius: 16px;
            padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: 0 8px 32px rgb(0 0 0 / .3);
        }
        h1 { font-size: clamp(1.5rem, 5vw, 2rem); margin: 0 0 .25rem; }
        h2 { font-size: 1.15rem; margin: 2rem 0 .5rem; padding-top: .5rem; border-top: 1px solid var(--border); }
        h2:first-of-type { border-top: 0; }
        h3 { font-size: 1rem; margin: 1.25rem 0 .35rem; }
        p, li { color: var(--fg); }
        p.lead, .meta { color: var(--muted); }
        .meta { font-size: .875rem; margin: 0 0 1.5rem; }
        a { color: var(--link); }
        a:hover { text-decoration: none; }
        :where(a, summary):focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; border-radius: 4px; }
        ul, ol { padding-inline-start: 1.25rem; }
        li { margin: .35rem 0; }
        table { width: 100%; border-collapse: collapse; margin: .75rem 0; font-size: .95rem; }
        th, td { text-align: start; padding: .55rem .5rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        nav.toc { background: rgb(255 255 255 / .04); border: 1px solid var(--border); border-radius: 12px; padding: 1rem 1.25rem; margin: 1.5rem 0; }
        nav.toc h2 { font-size: .95rem; margin: 0 0 .5rem; border: 0; padding: 0; }
        nav.toc ul { margin: 0; }
        .foot { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid var(--border); font-size: .875rem; color: var(--muted); }
        .skip { position: absolute; inset-inline-start: -9999px; }
        .skip:focus { inset-inline-start: 1rem; top: 1rem; background: var(--card); padding: .5rem .75rem; border-radius: 8px; z-index: 10; }
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
        @media (prefers-color-scheme: light) {
            :root { --bg: #f5f5f7; --card: #ffffff; --fg: #16181d; --muted: #5a6170; --border: #e2e4e9; --link: #b4267a; }
        }
    </style>
</head>
<body>
<a class="skip" href="#content">דילוג לתוכן</a>
<main id="content">
    <h1>{{ $title }}</h1>
    <p class="meta">
        {{ config('legal.company.name') }}
        @if (filled(config('legal.company.number')))
            · ח.פ. {{ config('legal.company.number') }}
        @endif
        · עודכן לאחרונה:
        <time datetime="{{ config('legal.updated_at') }}">
            {{ \Illuminate\Support\Carbon::parse(config('legal.updated_at'))->format('d/m/Y') }}
        </time>
    </p>

    {{ $slot }}

    <p class="foot">
        <a href="{{ route('legal.privacy') }}">מדיניות פרטיות</a> ·
        <a href="{{ route('legal.terms') }}">תנאי שימוש</a> ·
        פניות: <a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>
        @if (filled(config('legal.company.address')))
            <br>{{ config('legal.company.address') }}
        @endif
        @if (filled(config('legal.company.phone')))
            · {{ config('legal.company.phone') }}
        @endif
    </p>
</main>
</body>
</html>
