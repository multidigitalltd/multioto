<x-mail::message>
<div dir="rtl" style="text-align: right !important; direction: rtl !important;">
{{-- Body is operator-authored plain text: escaped first, then newlines become <br>. --}}
{!! nl2br(e($bodyText)) !!}
</div>
</x-mail::message>
