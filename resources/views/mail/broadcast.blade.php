<x-mail::message>
<div dir="rtl">
{{-- המילה "פרסומת" יושבת בסוגריים בסוף שורת הנושא (BroadcastRenderer::subject)
     ולא ככותרת בגוף ההודעה: שם רואים אותה לפני שפותחים את המייל, וזו גם
     הכותרת שהחוק מבקש מהודעה פרסומית במייל לשאת. --}}

@if (filled($bodyHtml ?? null))
{{-- The rich editor's HTML, already run through the allow-list sanitizer. --}}
{!! $bodyHtml !!}
@else
{!! nl2br(e($bodyText)) !!}
@endif

<hr style="border:0;border-top:1px solid #e5e7eb;margin:2rem 0 1rem">

{{-- זהות השולח (שם, כתובת, טלפון) מגיעה מהכותרת התחתונה שבהגדרות הדיוור
     ומודפסת אוטומטית בתחתית כל מייל על ידי vendor/mail/html/message.
     כאן נוסף רק ההסבר "למה קיבלת את זה", שניתן לעריכה בהגדרות, ובפרסומת
     גם קישור ההסרה שהחוק מחייב. --}}
<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.7">
    {!! nl2br(e($footer['note'] ?? '')) !!}<br>
    @if ($footer['is_marketing'] ?? false)
        אינך מעוניין לקבל דיוור פרסומי?
        @if (filled($footer['unsubscribe_url'] ?? null))
            <a href="{{ $footer['unsubscribe_url'] }}" style="color:#1f6feb">להסרה מרשימת התפוצה</a>.<br>
        @else
            {{-- בדיקה פנימית: הקישור מושבת בכוונה כדי שלחיצה בטעות לא תסיר לקוח אמיתי. --}}
            <span style="text-decoration:underline">להסרה מרשימת התפוצה</span>
            <em>(בבדיקה הקישור מושבת — בהודעה ללקוח הוא פעיל)</em>.<br>
        @endif
        הודעות שירות — חשבוניות, דרישות תשלום והתראות על תקלה באתר — יישלחו אליך גם לאחר ההסרה.
    @elseif (filled($footer['support'] ?? null))
        לכל שאלה אפשר להשיב למייל הזה או לכתוב לנו:
        <a href="mailto:{{ $footer['support'] }}" style="color:#1f6feb">{{ $footer['support'] }}</a>.
    @else
        לכל שאלה אפשר פשוט להשיב למייל הזה.
    @endif
</p>
</div>
</x-mail::message>
