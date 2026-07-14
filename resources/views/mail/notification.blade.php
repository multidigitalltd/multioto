<x-mail::message>
<div dir="rtl">
{{-- Body is operator-authored plain text: escaped first, then newlines become <br>. --}}
{!! nl2br(e($bodyText)) !!}
</div>
</x-mail::message>
