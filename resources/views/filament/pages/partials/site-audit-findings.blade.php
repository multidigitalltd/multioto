@php
    $groups = [
        'critical' => ['דורש טיפול מיידי', 'danger'],
        'warning' => ['חשוב לתקן', 'warning'],
        'notice' => ['מומלץ לשפר', 'info'],
        'ok' => ['נבדק ותקין', 'success'],
    ];
@endphp

<div class="space-y-5 text-sm" dir="rtl">
    <p class="text-gray-500 dark:text-gray-400">
        נבדק ב-{{ $audit->finished_at?->format('d/m/Y H:i') ?? $audit->created_at->format('d/m/Y H:i') }} ·
        <span dir="ltr">{{ $audit->summary['final_url'] ?? $audit->url }}</span>
    </p>

    @foreach ($groups as $severity => [$label, $color])
        @php $items = $audit->of($severity); @endphp
        @continue($items === [])

        <section>
            <h3 class="font-semibold mb-2">
                {{ $label }}
                <x-filament::badge :color="$color" class="align-middle">{{ count($items) }}</x-filament::badge>
            </h3>

            <ul class="space-y-3">
                @foreach ($items as $item)
                    <li class="border-s-4 ps-3 border-{{ $color }}-500">
                        <div class="font-medium">{{ $item['title'] }}</div>

                        @if (! empty($item['detail']))
                            <div class="text-gray-600 dark:text-gray-300">{{ $item['detail'] }}</div>
                        @endif

                        @if (! empty($item['fix']))
                            <div class="mt-1 text-gray-700 dark:text-gray-200">
                                <span class="font-medium">מה צריך לעשות:</span> {{ $item['fix'] }}
                            </div>
                        @endif

                        @if (! empty($item['evidence']))
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $item['evidence'] }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
