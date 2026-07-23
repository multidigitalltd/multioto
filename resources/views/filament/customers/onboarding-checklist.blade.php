@php
    $customer = $getRecord();
    $service = app(\App\Services\Customers\OnboardingChecklist::class);
    $items = $service->items($customer);
    $progress = $service->progress($customer);
@endphp

<div>
    <div class="mb-3 flex items-center gap-3">
        <span class="text-sm font-medium">{{ $progress['done'] }} מתוך {{ $progress['total'] }} שלבים הושלמו</span>
        <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"
             role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['done'] }}"
             aria-label="התקדמות קליטת הלקוח">
            <div class="h-full rounded-full bg-primary-500 transition-all"
                 style="width: {{ $progress['total'] > 0 ? round($progress['done'] / $progress['total'] * 100) : 0 }}%"></div>
        </div>
    </div>

    <ul class="grid gap-2" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
        @foreach ($items as $item)
            <li class="flex items-start gap-3 rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                @if ($item['auto'])
                    {{-- Auto-detected: the data says it's done — not toggleable. --}}
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-success-500 text-white"
                          aria-hidden="true">✓</span>
                @else
                    <button type="button"
                            wire:click="toggleOnboarding('{{ $item['key'] }}')"
                            role="checkbox"
                            aria-checked="{{ $item['done'] ? 'true' : 'false' }}"
                            aria-label="{{ $item['label'] }}"
                            @class([
                                'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border transition',
                                'focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-500',
                                'border-success-500 bg-success-500 text-white' => $item['done'],
                                'border-gray-300 bg-transparent hover:border-primary-400 dark:border-gray-600' => ! $item['done'],
                            ])>
                        @if ($item['done'])✓@endif
                    </button>
                @endif

                <div class="min-w-0">
                    <div @class(['text-sm font-medium', 'text-gray-400 line-through dark:text-gray-500' => $item['done']])>
                        {{ $item['label'] }}
                        @if ($item['auto'])
                            <span class="ms-1 text-xs font-normal text-success-600 dark:text-success-400">אוטומטי</span>
                        @elseif ($item['done'])
                            <span class="ms-1 text-xs font-normal text-gray-400">סומן ידנית</span>
                        @endif
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</div>
                </div>
            </li>
        @endforeach
    </ul>
</div>
