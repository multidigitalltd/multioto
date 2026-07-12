<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { direction: rtl; color: #16181d; font-size: 12px; line-height: 1.6; margin: 0; padding: 24px 28px; }
        .head { text-align: center; border-bottom: 2px solid #4f46e5; padding-bottom: 14px; margin-bottom: 20px; }
        .head img { max-height: 60px; margin-bottom: 8px; }
        h1 { font-size: 20px; margin: 4px 0 2px; }
        .sub { color: #55606e; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { text-align: right; padding: 7px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { width: 34%; color: #55606e; font-weight: bold; background: #f6f7f9; }
        .sig-box { margin-top: 10px; border: 1px solid #c2c8d0; border-radius: 8px; padding: 10px; }
        .sig-box .label { color: #55606e; font-size: 11px; margin-bottom: 6px; }
        .sig-box img { max-height: 120px; max-width: 100%; }
        .foot { margin-top: 22px; color: #55606e; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="head">
        @if ($logo)
            <img src="{{ $logo }}" alt="לוגו">
        @endif
        <h1>כרטיס לקוח חתום</h1>
        <div class="sub">מולטי דיגיטל | נוצר בתאריך {{ $generatedAt }}</div>
    </div>

    <table>
        <tr><th>שם העסק</th><td>{{ $customer->name }}</td></tr>
        <tr><th>איש קשר</th><td>{{ $customer->contact_name ?: '—' }}</td></tr>
        <tr><th>סוג עסק</th><td>{{ $customer->business_type?->getLabel() ?? '—' }}</td></tr>
        <tr><th>ח.פ / עוסק</th><td>{{ $customer->business_number ?: '—' }}</td></tr>
        <tr><th>מייל</th><td>{{ $customer->email ?: '—' }}</td></tr>
        <tr><th>טלפון</th><td>{{ $customer->phone ?: '—' }}</td></tr>
        <tr><th>אמצעי תשלום</th><td>{{ $paymentMethod }}</td></tr>
        <tr><th>אישור תנאים</th><td>{{ $customer->terms_accepted_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>
    </table>

    <div class="sig-box">
        <div class="label">חתימת הלקוח:</div>
        @if ($signature)
            <img src="{{ $signature }}" alt="חתימה">
        @else
            <em>—</em>
        @endif
    </div>

    <div class="foot">
        מסמך זה נוצר אוטומטית עם השלמת טופס פתיחת הכרטיס המקוון, ומהווה רשומת הסכמה חתומה.
        כתובת ה-IP של ממלא הטופס: {{ $customer->signed_ip ?: '—' }} | חתימה בתאריך {{ $customer->terms_accepted_at?->format('d/m/Y H:i') ?? '—' }}.
    </div>
</body>
</html>
