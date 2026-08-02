<x-filament-widgets::widget>
    <x-filament::section
        :icon="$this->anythingStopped() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-wrench-screwdriver'"
        icon-color="{{ $this->anythingStopped() ? 'danger' : 'warning' }}"
    >
        <x-slot name="heading">תקינות המערכת</x-slot>

        <x-slot name="description">
            @if ($this->anythingStopped())
                חלק מהמערכת הפסיק לעבוד — עבודות מתוזמנות (חיובים, דאנינג, התראות) אינן מתבצעות כרגע.
            @else
                המערכת עובדת, אבל יש מה לבדוק.
            @endif
        </x-slot>

        <ul class="space-y-2">
            @foreach ($this->failures() as $check)
                <li class="flex items-start gap-2 text-sm">
                    <x-filament::badge :color="$check['status'] === 'down' ? 'danger' : 'warning'">
                        {{ $check['status'] === 'down' ? 'מושבת' : 'לבדיקה' }}
                    </x-filament::badge>
                    <span>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $check['label'] }}</span>
                        <span class="text-gray-600 dark:text-gray-400">— {{ $check['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
