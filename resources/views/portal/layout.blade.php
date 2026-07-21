<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'האזור האישי') — מולטי דיגיטל</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7; --card: #ffffff; --fg: #16181d; --muted: #55606e;
            --border: #c2c8d0; --brand: #1c5fd6; --brand-fg: #ffffff;
            --error: #b3261e; --ok-bg: #dff3e4; --ok-fg: #14532d; --chip: #eef1f6;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #16181d; --card: #23262d; --fg: #f4f5f7; --muted: #a3adba;
                --border: #3a3f48; --brand: #6ea8fe; --brand-fg: #0b1220;
                --error: #ff6b6b; --ok-bg: #14311f; --ok-fg: #b9f0cd; --chip: #2b2f37;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
            font-family: "Rubik", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            line-height: 1.6;
        }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
        header.site {
            display: flex; justify-content: space-between; align-items: center;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;
        }
        header.site img { height: 2.2rem; width: auto; }
        header.site .brand { font-weight: 700; font-size: 1.15rem; }
        .who { color: var(--muted); font-size: .9rem; }
        .logout { background: none; border: 0; color: var(--brand); text-decoration: underline; cursor: pointer; font: inherit; padding: .25rem; }
        nav.tabs { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        nav.tabs a {
            padding: .5rem .9rem; border-radius: 999px; text-decoration: none;
            background: var(--chip); color: var(--fg); font-size: .95rem; border: 1px solid transparent;
        }
        nav.tabs a[aria-current="page"] { background: var(--brand); color: var(--brand-fg); }
        nav.tabs a:focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: 14px;
            padding: 1.1rem 1.15rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgb(0 0 0 / .05);
        }
        h1 { font-size: 1.4rem; margin: 0 0 1rem; }
        h2 { font-size: 1.05rem; margin: 0 0 .6rem; }
        p { margin: 0 0 .6rem; }
        .muted { color: var(--muted); }
        a.btn, button.btn {
            display: inline-block; padding: .7rem 1.1rem; border-radius: 10px; border: 0;
            background: var(--brand); color: var(--brand-fg); text-decoration: none; font: inherit;
            font-weight: 600; cursor: pointer;
        }
        a.btn:hover, button.btn:hover { filter: brightness(1.05); }
        a.btn:focus-visible, button.btn:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        a.btn.ghost { background: transparent; color: var(--brand); border: 1px solid var(--brand); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: right; padding: .6rem .5rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { font-weight: 600; color: var(--muted); font-size: .85rem; }
        .status-chip { display: inline-block; padding: .1rem .55rem; border-radius: 999px; background: var(--chip); font-size: .82rem; white-space: nowrap; }
        .notice { background: var(--ok-bg); color: var(--ok-fg); border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .empty { color: var(--muted); padding: 1rem 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; }
        .stat { background: var(--chip); border-radius: 12px; padding: .8rem 1rem; }
        .stat b { display: block; font-size: 1.5rem; }
        .table-scroll { overflow-x: auto; }
        footer.site { color: var(--muted); font-size: .82rem; margin-top: 2rem; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="site">
            <div style="display:flex;align-items:center;gap:.6rem;">
                @if ($logo = \App\Support\Branding::logoUrl())
                    <img src="{{ $logo }}" alt="מולטי דיגיטל">
                @else
                    <span class="brand">מולטי דיגיטל</span>
                @endif
            </div>
            @isset($customer)
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="who">{{ $customer->name }}</span>
                    <form method="POST" action="{{ route('portal.logout') }}">
                        @csrf
                        <button type="submit" class="logout">התנתקות</button>
                    </form>
                </div>
            @endisset
        </header>

        @isset($customer)
            <nav class="tabs" aria-label="ניווט האזור האישי">
                <a href="{{ route('portal.dashboard') }}" @if (request()->routeIs('portal.dashboard')) aria-current="page" @endif>סקירה</a>
                <a href="{{ route('portal.debt') }}" @if (request()->routeIs('portal.debt')) aria-current="page" @endif>תשלומים פתוחים</a>
                <a href="{{ route('portal.invoices') }}" @if (request()->routeIs('portal.invoices')) aria-current="page" @endif>חשבוניות</a>
                <a href="{{ route('portal.tickets') }}" @if (request()->routeIs('portal.tickets')) aria-current="page" @endif>פניות</a>
            </nav>
        @endisset

        @if (session('status'))
            <div class="notice" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')

        <footer class="site">מולטי דיגיטל · האזור האישי</footer>
    </div>
</body>
</html>
