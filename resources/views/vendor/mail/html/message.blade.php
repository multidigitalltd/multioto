<x-mail::layout>
{{-- Header: the business logo when one is uploaded, otherwise the sender name. --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@php($logo = \App\Support\Branding::logoDataUri())
@if ($logo)
<img src="{{ $logo }}" alt="{{ config('mail.from.name') ?: config('app.name') }}" style="max-height: 48px; width: auto; border: none;">
@else
{{ config('mail.from.name') ?: config('app.name') }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: the configurable business footer (falls back to name + year). --}}
<x-slot:footer>
<x-mail::footer>
{!! nl2br(e(\App\Support\Branding::emailFooter())) !!}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
