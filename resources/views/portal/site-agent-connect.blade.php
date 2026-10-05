@extends('portal.layout')

@section('title', 'חיבור האתר לבוט')

@php
    use App\Http\Controllers\Portal\PortalSiteAgentController as Agent;
@endphp

@section('content')
    <p><a href="{{ route('portal.site-agent') }}">→ חזרה לבוט ניהול האתר</a></p>
    <h1>חיבור <span dir="ltr">{{ $site->domain }}</span></h1>

    <div class="card">
        <h2 style="margin-top:0;">מצב החיבור: {{ $status['label'] }}</h2>
        <p>{{ $status['detail'] }}</p>

        @if ($status['state'] !== 'not_installed' && $status['state'] !== 'pending')
            <form method="POST" action="{{ route('portal.site-agent.check', ['site' => $site]) }}">
                @csrf
                <button type="submit" class="btn">בדקו את החיבור עכשיו</button>
            </form>
        @endif
    </div>

    @if (! $entitled)
        {{-- No codes for an unpaid service: they are the keys to the site, and
             handing them out is what the subscription pays for. --}}
        <div class="card">
            <h2 style="margin-top:0;">המנוי אינו פעיל כרגע</h2>
            <p>קודי החיבור מוצגים כשהמנוי פעיל. <a href="{{ route('portal.debt') }}">לתשלומים ולעדכון אמצעי תשלום</a></p>
        </div>
    @else
        <div class="card">
            @include('partials.agent-install-guide', [
                'codes' => $codes,
                'downloadUrl' => route('portal.site-agent.plugin'),
                'checkSlot' => 'חזרו לעמוד הזה — "מצב החיבור" למעלה יתחלף ל"מחובר" תוך דקה. אחר כך שלחו הודעה לבוט בוואטסאפ.',
                'helpUrl' => route('portal.tickets'),
            ])
        </div>
    @endif
@endsection
