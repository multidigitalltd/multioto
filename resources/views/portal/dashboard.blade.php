@extends('portal.layout')

@section('title', 'סקירה')

@section('content')
    <h1>שלום {{ $customer->name }} 👋</h1>

    <div class="card">
        <div class="grid">
            <div class="stat"><b>{{ $subscriptions->count() }}</b><span class="muted">מנויים פעילים</span></div>
            <div class="stat"><b>{{ $invoiceCount }}</b><span class="muted">חשבוניות</span></div>
            <div class="stat"><b>{{ $openTicketCount }}</b><span class="muted">פניות פתוחות</span></div>
        </div>
    </div>

    <div class="card">
        <h2>אמצעי תשלום</h2>
        @if ($hasCard)
            <p class="muted">כרטיס אשראי שמור במערכת. אפשר להחליף אותו בכל עת.</p>
        @else
            <p class="muted">לא נמצא כרטיס שמור. הוספת כרטיס מאפשרת חידוש אוטומטי של המנויים.</p>
        @endif
        <a href="{{ route('portal.card') }}" class="btn">{{ $hasCard ? 'עדכון אמצעי תשלום' : 'הוספת כרטיס' }}</a>
    </div>

    <div class="card">
        <h2>המנויים שלי</h2>
        @forelse ($subscriptions as $subscription)
            <div style="padding:.6rem 0;border-bottom:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                    <strong>{{ $subscription->planName() }}</strong>
                    <span class="status-chip">{{ $subscription->status?->getLabel() }}</span>
                </div>
                @if ($subscription->site?->domain)
                    <div class="muted" dir="ltr" style="text-align:right;">{{ $subscription->site->domain }}</div>
                @endif
                <div class="muted">
                    {{ \App\Support\Money::ils($subscription->basePriceAgorot()) }}
                    @if ($subscription->next_charge_at)
                        · חיוב הבא: {{ $subscription->next_charge_at->format('d/m/Y') }}
                    @endif
                </div>
            </div>
        @empty
            <p class="empty">אין מנויים פעילים כרגע.</p>
        @endforelse
    </div>
@endsection
