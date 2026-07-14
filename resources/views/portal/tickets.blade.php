@extends('portal.layout')

@section('title', 'פניות')

@section('content')
    <h1>הפניות שלי</h1>

    <div class="card">
        @if ($tickets->isEmpty())
            <p class="empty">אין פניות להצגה.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>נושא</th>
                            <th>סטטוס</th>
                            <th>עודכן</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tickets as $ticket)
                            <tr>
                                <td>{{ $ticket->subject ?: 'פנייה #'.$ticket->id }}</td>
                                <td><span class="status-chip">{{ $ticket->status?->getLabel() ?? '—' }}</span></td>
                                <td>{{ $ticket->updated_at?->format('d/m/Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>פנייה חדשה</h2>
        <p class="muted">רוצים לפתוח פנייה חדשה? אפשר דרך טופס יצירת הקשר.</p>
        <a href="{{ route('support.form') }}" class="btn ghost">יצירת קשר ותמיכה</a>
    </div>
@endsection
