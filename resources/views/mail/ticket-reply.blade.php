<x-mail::message>
<div dir="rtl" style="text-align: right !important; direction: rtl !important;">
@if (filled($bodyHtml))
{{-- Pre-sanitized by EmailBody::toSafeHtml (allow-list) before it reached here. --}}
{!! $bodyHtml !!}
@else
{!! nl2br(e($bodyText)) !!}
@endif
</div>
</x-mail::message>
