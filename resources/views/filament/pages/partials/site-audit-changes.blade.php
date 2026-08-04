@php
    /** @var \App\Services\Audit\Comparison $comparison */
    $fixed = $comparison->available() ? $comparison->fixed() : [];
    $appeared = $comparison->available() ? $comparison->appeared() : [];
    $remaining = $comparison->available() ? $comparison->remaining() : [];

    $sections = [
        ['תוקנו מאז', $fixed, 'success', 'היו בבדיקה הקודמת ואינם עוד.'],
        ['חדשים', $appeared, 'danger', 'לא היו בבדיקה הקודמת והופיעו עכשיו.'],
        ['עדיין פתוחים', $remaining, 'warning', 'היו קודם ועדיין כאן.'],
    ];
@endphp

<div class="space-y-5 text-sm" dir="rtl">
    @if (! $comparison->available())
        <p class="text-gray-500 dark:text-gray-400">{{ $comparison->unavailable }}</p>
    @else
        <p class="text-gray-500 dark:text-gray-400">
            השוואה בין הבדיקה מ-{{ $comparison->previous->finished_at?->format('d/m/Y H:i') ?? $comparison->previous->created_at->format('d/m/Y H:i') }}
            לבדיקה מ-{{ $comparison->current->finished_at?->format('d/m/Y H:i') ?? $comparison->current->created_at->format('d/m/Y H:i') }}.
        </p>

        @unless ($comparison->changed())
            <p class="font-medium">שום ממצא לא נפתר ולא נוסף בין שתי הבדיקות.</p>
        @endunless

        @foreach ($sections as [$label, $items, $color, $note])
            @continue($items === [])

            <section>
                <h3 class="font-semibold mb-2">
                    {{ $label }}
                    <x-filament::badge :color="$color" class="align-middle">{{ count($items) }}</x-filament::badge>
                </h3>

                <p class="mb-2 text-gray-500 dark:text-gray-400">{{ $note }}</p>

                <ul class="space-y-2">
                    @foreach ($items as $item)
                        <li class="border-s-4 ps-3 border-{{ $color }}-500">
                            <div class="font-medium">{{ $item['title'] }}</div>

                            @if (! empty($item['area']))
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item['area'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
