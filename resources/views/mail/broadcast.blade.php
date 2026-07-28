<x-mail::message>
<div dir="rtl">
@if ($footer['is_marketing'] ?? false)
{{-- חוק התקשורת ס' 30א(ב)(1): הודעת פרסומת נושאת את המילה "פרסומת" בראשה. --}}
<p style="margin:0 0 1rem;font-size:12px;color:#6b7280;letter-spacing:.05em">פרסומת</p>
@endif

{!! nl2br(e($bodyText)) !!}

<hr style="border:0;border-top:1px solid #e5e7eb;margin:2rem 0 1rem">

{{-- זהות השולח (שם, כתובת, טלפון) מגיעה מהכותרת התחתונה שבהגדרות הדיוור
     ומודפסת אוטומטית בתחתית כל מייל על ידי vendor/mail/html/message.
     כאן נוסף רק מה שהיא לא מכסה: למה הלקוח קיבל את ההודעה, ובפרסומת —
     גם קישור ההסרה שהחוק מחייב. --}}
@if ($footer['is_marketing'] ?? false)
<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.7">
    זוהי הודעה פרסומית מאת {{ $footer['business'] }}.<br>
    אינך מעוניין לקבל דיוור פרסומי?
    @if (filled($footer['unsubscribe_url'] ?? null))
        <a href="{{ $footer['unsubscribe_url'] }}" style="color:#1f6feb">להסרה מרשימת התפוצה</a>.<br>
    @else
        {{-- בדיקה פנימית: הקישור מושבת בכוונה כדי שלחיצה בטעות לא תסיר לקוח אמיתי. --}}
        <span style="text-decoration:underline">להסרה מרשימת התפוצה</span>
        <em>(בבדיקה הקישור מושבת — בהודעה ללקוח הוא פעיל)</em>.<br>
    @endif
    הודעות שירות — חשבוניות, דרישות תשלום והתראות על תקלה באתר — יישלחו אליך גם לאחר ההסרה.
</p>
@else
{{-- אין כאן דבר על הסרה: הסרה שייכת להודעת פרסומת בלבד. אזכור שלה
     בהודעת שירות רק מזמין לקוח לנסות להפסיק לקבל חשבוניות והתראות
     על תקלה באתר שלו — דברים שאינם ניתנים להסרה. --}}
<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.7">
    זוהי הודעת שירות מאת {{ $footer['business'] }}.<br>
    קיבלת אותה משום שאתה לקוח שלנו והיא נוגעת לשירות שאנחנו מספקים לך.<br>
    @if (filled($footer['support'] ?? null))
        לכל שאלה אפשר להשיב למייל הזה או לכתוב לנו:
        <a href="mailto:{{ $footer['support'] }}" style="color:#1f6feb">{{ $footer['support'] }}</a>.
    @else
        לכל שאלה אפשר פשוט להשיב למייל הזה.
    @endif
</p>
@endif
</div>
</x-mail::message>
