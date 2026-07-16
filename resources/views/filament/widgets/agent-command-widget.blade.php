<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-command-line">
        <x-slot name="heading">מסוף פקודות לסוכן</x-slot>
        <x-slot name="headerEnd">
            <a href="{{ $this->consoleUrl() }}" class="text-xs text-primary-600 hover:underline dark:text-primary-400">
                למסוף המלא ←
            </a>
        </x-slot>

        @if ($this->awaitingReply)
            <div class="mb-2 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-700 dark:bg-warning-500/10">
                <p class="font-medium text-warning-800 dark:text-warning-200">הסוכן ממתין לתשובתך:</p>
                <p class="mt-1 whitespace-pre-line break-words text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($this->awaitingReply->result, 300) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">כתבו תשובה למטה ושלחו — הסוכן ימשיך מאותה נקודה.</p>
            </div>
        @endif

        <form wire:submit="run" class="flex flex-col gap-2">
            {{ $this->form }}
            <div class="flex justify-end">
                <x-filament::button type="submit" size="sm" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="run">שלח לסוכן</span>
                    <span wire:loading wire:target="run">הסוכן חושב…</span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
