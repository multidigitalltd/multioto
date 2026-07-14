<x-mail::message>
{{-- הטקסט מגיע מ-lang/he/dunning.php; Blade מבצע escaping מלא --}}
<div dir="rtl">
{!! nl2br(e($bodyText)) !!}
</div>
</x-mail::message>
