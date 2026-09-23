@extends('portal.layout')

@section('title', 'סוכן האתר')

@php
    use App\Http\Controllers\Portal\PortalSiteAgentController as Agent;

    $priceLabel = Agent::priceLabel($extraPrice);
@endphp

@section('content')
    <h1>סוכן האתר</h1>

    @if (! $entitled)
        {{-- Said first and plainly. Everything below still shows their numbers,
             and a customer reading a list of "managers" while nothing answers
             them concludes the product is broken rather than unpaid. --}}
        <div class="card">
            <h2 style="margin-top:0;">המנוי אינו פעיל כרגע</h2>
            <p>הסוכן אינו עונה כל עוד המנוי אינו פעיל. <strong>האתר עצמו ממשיך לעבוד כרגיל</strong>, ושום שינוי שכבר בוצע לא בוטל.</p>
            <p class="muted">
                <a href="{{ route('portal.debt') }}">לתשלומים פתוחים ולעדכון אמצעי תשלום</a>
            </p>
        </div>
    @endif

    <div class="card">
        <h2 style="margin-top:0;">המספרים שמנהלים את האתרים שלכם</h2>

        @if ($numbers->isEmpty())
            <p class="empty">עדיין אין מספר שמנהל אתר בחשבון הזה.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>מספר</th>
                            <th>שם</th>
                            <th>אתר</th>
                            <th>מצב</th>
                            <th><span class="visually-hidden">פעולות</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($numbers as $number)
                            <tr>
                                <td dir="ltr" style="text-align:right;">{{ $number->phone }}</td>
                                <td>{{ $number->name ?: '—' }}</td>
                                <td dir="ltr" style="text-align:right;">{{ $number->site?->domain ?? '—' }}</td>
                                <td>
                                    @if ($number->revoked_at !== null)
                                        הוסר
                                    @elseif ($number->verified_at === null)
                                        ממתין לאימות
                                    @elseif (! ($number->site?->mcp_enabled) || blank($number->site?->mcp_endpoint))
                                        {{-- The state that looks like a fault and is not: the
                                             number proved itself and the plugin is not connected
                                             yet. Naming it here is the difference between
                                             finishing the install and opening a ticket. --}}
                                        מאומת — האתר עדיין לא מחובר
                                    @else
                                        פעיל
                                    @endif
                                </td>
                                <td>
                                    @if ($number->revoked_at === null && $number->verified_at === null)
                                        <form method="POST" action="{{ route('portal.site-agent.resend', ['subscriber' => $number]) }}"
                                              style="display:inline;">
                                            @csrf
                                            <button type="submit" class="linkish">
                                                שלחו קוד שוב<span class="visually-hidden"> ל{{ $number->phone }}</span>
                                            </button>
                                        </form>
                                    @endif

                                    @if ($number->revoked_at === null)
                                        <form method="POST" action="{{ route('portal.site-agent.revoke', ['subscriber' => $number]) }}"
                                              style="display:inline;"
                                              onsubmit="return confirm('להסיר את {{ $number->phone }} מניהול האתר? הוא יפסיק לענות מיד, והתשלום עליו ייפסק מהמחזור הבא.');">
                                            @csrf
                                            <button type="submit" class="linkish">
                                                הסרה<span class="visually-hidden"> של {{ $number->phone }}</span>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($extraPrice !== null && $entitled)
        <div class="card">
            <h2 style="margin-top:0;">הוספת מספר נוסף</h2>
            {{-- The price and when it starts, in one sentence, above the form.
                 A charge that appears on an invoice the customer did not expect
                 is the same event as a dispute, whatever the button said. --}}
            <p>
                שותף, מנהלת משרד או מי שמתחזק לכם את האתר — כל אחד יכול לנהל מהמספר שלו.
                @if ($extraPrice > 0)
                    מספר נוסף עולה <strong>{{ $priceLabel }}</strong>
                    {{ $subscription?->plan?->intervalLabel() ?? 'לחודש' }},
                    והוא יתווסף לחיוב מהמחזור הבא — לא נגבה עליו תשלום עכשיו.
                @else
                    בחשבון שלכם מספר נוסף אינו כרוך בתוספת תשלום.
                @endif
            </p>

            @if ($errors->any())
                <div role="alert" style="color:#dc2626;padding:.4rem 0;">
                    @foreach ($errors->all() as $message)<p style="margin:.2rem 0;">{{ $message }}</p>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('portal.site-agent.add') }}" class="agent-form" novalidate>
                @csrf

                <div class="field">
                    <label for="site_id">האתר</label>
                    <select id="site_id" name="site_id" required>
                        @foreach ($sites as $id => $domain)
                            <option value="{{ $id }}" @selected(old('site_id') == $id)>{{ $domain }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="phone">מספר הוואטסאפ</label>
                    <input id="phone" name="phone" type="tel" required dir="ltr" placeholder="050-1234567"
                           value="{{ old('phone') }}" aria-describedby="phone-hint">
                    <p class="muted" id="phone-hint" style="font-size:.9rem;margin:.3rem 0 0;">
                        למספר יישלח קוד בן 6 ספרות בוואטסאפ. עד שישיב עליו הוא אינו יכול לעשות דבר באתר.
                    </p>
                </div>

                <div class="field">
                    <label for="name">שם</label>
                    <input id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}">
                </div>

                <div class="field confirm">
                    <input id="confirm" name="confirm" type="checkbox" value="1" required>
                    <label for="confirm">
                        @if ($extraPrice > 0)
                            אני מאשר/ת שהחיוב התקופתי יגדל ב־{{ $priceLabel }} החל מהמחזור הבא.
                        @else
                            אני מאשר/ת את הוספת המספר.
                        @endif
                    </label>
                </div>

                <button type="submit" class="btn">הוספת המספר</button>
            </form>
        </div>
    @elseif ($entitled)
        <div class="card">
            <h2 style="margin-top:0;">הוספת מספר נוסף</h2>
            <p class="muted">המסלול שלכם אינו כולל מספרים נוספים. <a href="{{ route('portal.tickets') }}">כתבו לנו</a> ונשדרג.</p>
        </div>
    @endif

    <style>
        .agent-form .field { margin-bottom: 1rem; text-align: start; }
        .agent-form label { display: block; font-weight: 600; margin-bottom: .35rem; }
        .agent-form input[type=text], .agent-form input[type=tel], .agent-form select {
            width: 100%; padding: .6rem .75rem; border-radius: 8px;
            border: 1px solid var(--border, #c2c8d0); background: transparent; color: inherit;
            font: inherit;
        }
        .agent-form .confirm { display: flex; gap: .5rem; align-items: flex-start; }
        .agent-form .confirm input { margin-top: .4rem; width: 1.1rem; height: 1.1rem; flex: none; }
        .agent-form .confirm label { font-weight: 400; margin: 0; }
        .agent-form :focus-visible { outline: 2px solid #2563eb; outline-offset: 1px; }
        /* A row action that is a form, not a link: same affordance, and still a
           button so it is reached and announced as one. */
        .linkish {
            background: none; border: 0; color: var(--brand, #1c5fd6); text-decoration: underline;
            cursor: pointer; font: inherit; padding: .25rem 0; margin-inline-end: .6rem;
        }
        .linkish:focus-visible { outline: 2px solid var(--brand, #1c5fd6); outline-offset: 2px; }
    </style>
@endsection
