@extends('portal.layout')

@section('title', 'פניות')

@section('content')
    <h1>הפניות שלי</h1>

    @if (session('status'))
        <div class="card" role="status" style="border-inline-start:4px solid #16a34a">
            {{ session('status') }}
        </div>
    @endif

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
        <h2>פתיחת פנייה חדשה</h2>

        @if ($errors->any())
            <p class="empty" role="alert" style="color:#dc2626">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('portal.tickets.store') }}" class="ticket-form" novalidate>
            @csrf
            <div class="field">
                <label for="subject">נושא</label>
                <input type="text" id="subject" name="subject" maxlength="150" required
                       value="{{ old('subject') }}" autocomplete="off">
            </div>
            <div class="field">
                <label for="message">פירוט הבקשה</label>
                <textarea id="message" name="message" rows="5" maxlength="5000" required
                          placeholder="ספרו לנו במה נוכל לעזור">{{ old('message') }}</textarea>
            </div>
            <button type="submit" class="btn">שליחת הפנייה</button>
        </form>
    </div>

    <style>
        .ticket-form .field { margin-bottom: 1rem; text-align: start; }
        .ticket-form label { display: block; font-weight: 600; margin-bottom: .35rem; }
        .ticket-form input, .ticket-form textarea {
            width: 100%; padding: .6rem .75rem; border-radius: 8px;
            border: 1px solid var(--border, #c2c8d0); background: transparent; color: inherit;
            font: inherit; resize: vertical;
        }
        .ticket-form input:focus-visible, .ticket-form textarea:focus-visible {
            outline: 2px solid #2563eb; outline-offset: 1px;
        }
    </style>
@endsection
