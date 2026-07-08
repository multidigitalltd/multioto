<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        לחצו "בדוק" ליד כל אינטגרציה כדי לוודא שהמפתחות שהוזנו עובדים. הבדיקה מתבצעת מול השרת של הספק בזמן אמת.
    </p>

    <div class="mt-4 grid gap-3">
        @foreach ($this->integrations as $key => $meta)
            @php($result = $results[$key] ?? null)
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="font-semibold">{{ $meta['label'] }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $meta['description'] }}</div>
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($result)
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium',
                                'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400' => $result['state'] === 'ok',
                                'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400' => $result['state'] === 'fail',
                                'bg-gray-100 text-gray-600 dark:bg-gray-500/20 dark:text-gray-400' => $result['state'] === 'unconfigured',
                            ])>
                                {{ $result['state'] === 'ok' ? '✓ תקין' : ($result['state'] === 'fail' ? '✗ תקלה' : '— לא מוגדר') }}
                            </span>
                        @endif

                        <x-filament::button
                            wire:click="test('{{ $key }}')"
                            wire:target="test('{{ $key }}')"
                            wire:loading.attr="disabled"
                            size="sm"
                            color="gray"
                            icon="heroicon-o-signal">
                            <span wire:loading.remove wire:target="test('{{ $key }}')">בדוק</span>
                            <span wire:loading wire:target="test('{{ $key }}')">בודק…</span>
                        </x-filament::button>
                    </div>
                </div>

                @if ($result && $result['message'])
                    <p @class([
                        'mt-2 text-sm',
                        'text-danger-600 dark:text-danger-400' => $result['state'] === 'fail',
                        'text-gray-500 dark:text-gray-400' => $result['state'] !== 'fail',
                    ])>{{ $result['message'] }}</p>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
