@extends('portal.layout')

@section('title', 'כניסה לאזור האישי')

@section('content')
    <div class="card">
        <h1>כניסה לאזור האישי</h1>
        <p class="muted">הזינו את כתובת האימייל הרשומה אצלנו ונשלח אליכם קישור כניסה. באזור האישי אפשר לצפות בחשבוניות, לעקוב אחר פניות ולעדכן אמצעי תשלום.</p>

        <form method="POST" action="{{ route('portal.login.send') }}" style="margin-top:1rem;">
            @csrf
            <label for="email" style="display:block;font-weight:600;margin-bottom:.4rem;">אימייל</label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email') }}"
                required
                autocomplete="email"
                autofocus
                dir="ltr"
                style="width:100%;padding:.7rem;border:1px solid var(--border);border-radius:10px;background:transparent;color:var(--fg);font:inherit;text-align:right;"
                aria-describedby="@error('email') email-error @enderror"
                @error('email') aria-invalid="true" @enderror
            >
            @error('email')
                <p style="color:var(--error);font-size:.9rem;margin:.5rem 0 0;" id="email-error" role="alert">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn" style="margin-top:1rem;">שליחת קישור כניסה</button>
        </form>
    </div>

    <div class="card">
        <h2>צריכים עזרה?</h2>
        <p class="muted">אם אינכם זוכרים באיזו כתובת אתם רשומים, או שאתם צריכים לפתוח פנייה, אפשר ליצור איתנו קשר.</p>
        <a href="{{ route('support.form') }}" class="btn ghost">יצירת קשר ותמיכה</a>
    </div>
@endsection
