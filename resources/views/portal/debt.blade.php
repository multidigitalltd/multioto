@extends('portal.layout')

@section('title', 'תשלומים פתוחים')

@section('content')
    <h1>תשלומים פתוחים</h1>

    @if ($charges->isEmpty())
        <div class="card">
            <p class="empty">אין תשלומים פתוחים — הכול משולם. תודה! 🎉</p>
        </div>
    @else
        <div class="card">
            <p>סה״כ לתשלום: <strong>{{ \App\Support\Money::ils($totalAgorot) }}</strong></p>
            <p class="muted">אפשר לשלם בכרטיס אשראי, ובחלק מהתשלומים גם ב-Bit. לתשלום בהעברה בנקאית ניתן לפנות אלינו.</p>
        </div>

        @foreach ($charges as $charge)
            <div class="card">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:baseline;">
                    <strong>{{ $charge->description ?: 'חיוב' }}</strong>
                    <span style="font-size:1.15rem;font-weight:700;">{{ \App\Support\Money::ils((int) $charge->total_agorot) }}</span>
                </div>

                @if ($site = $charge->subscription?->site?->domain)
                    <div class="muted" dir="ltr" style="text-align:right;">{{ $site }}</div>
                @endif

                <div class="muted">נדרש לתשלום: {{ $charge->demand_sent_at?->format('d/m/Y') ?? $charge->created_at?->format('d/m/Y') }}</div>

                <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.9rem;">
                    <a href="{{ \App\Support\PaymentLink::for($charge->id) }}" class="btn">תשלום בכרטיס אשראי</a>
                    @if (filled($charge->cardcom_bit_url))
                        <a href="{{ \App\Support\PaymentLink::bitFor($charge->id) }}" class="btn ghost">⚡ תשלום ב-Bit</a>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
@endsection
