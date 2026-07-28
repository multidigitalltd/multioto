<x-mail::message>
<div dir="rtl">
@if ($footer['is_marketing'] ?? false)
{{-- חוק התקשורת ס' 30א(ב)(1): הודעת פרסומת נושאת את המילה "פרסומת" בראשה. --}}
<p style="margin:0 0 1rem;font-size:12px;color:#6b7280;letter-spacing:.05em">פרסומת</p>
@endif

{!! nl2br(e($bodyText)) !!}

<hr style="border:0;border-top:1px solid #e5e7eb;margin:2rem 0 1rem">

@if ($footer['is_marketing'] ?? false)
<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.7">
    הודעה זו נשלחה על ידי {{ $footer['business'] }}@if (filled($footer['address'] ?? null)), {{ $footer['address'] }}@endif.<br>
    אינך מעוניין לקבל דיוור פרסומי?
    <a href="{{ $footer['unsubscribe_url'] }}" style="color:#1f6feb">להסרה מרשימת התפוצה</a>.<br>
    הודעות שירות — חשבוניות, דרישות תשלום והתראות על תקלה באתר — יישלחו אליך גם לאחר ההסרה.
</p>
@else
{{-- הודעת שירות: לא פרסומת, ולכן אין הסרה — אבל הלקוח עדיין צריך לדעת
     מי שלח, למה קיבל את זה, ולאן לפנות. --}}
<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.7">
    זוהי הודעת שירות מאת {{ $footer['business'] }}@if (filled($footer['address'] ?? null)), {{ $footer['address'] }}@endif.<br>
    קיבלת אותה משום שאתה לקוח שלנו והיא נוגעת לשירות שאנחנו מספקים לך —
    אין מדובר בהודעה פרסומית, ולכן היא נשלחת גם למי שהוסר מרשימת הדיוור.<br>
    @if ($support = config('billing.email.support_address'))
        לכל שאלה אפשר להשיב למייל הזה או לכתוב לנו:
        <a href="mailto:{{ $support }}" style="color:#1f6feb">{{ $support }}</a>.
    @else
        לכל שאלה אפשר פשוט להשיב למייל הזה.
    @endif
</p>
@endif
</div>
</x-mail::message>
