<x-mail::message>
<div dir="rtl">
@if (filled($bodyHtml))
{{-- Pre-sanitized by EmailBody::toSafeHtml (allow-list) before it reached here. --}}
{!! $bodyHtml !!}
@else
{!! nl2br(e($bodyText)) !!}
@endif
</div>
</x-mail::message>
