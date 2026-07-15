<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-command-line">
        <x-slot name="heading">מסוף פקודות לסוכן</x-slot>
        <x-slot name="headerEnd">
            <a href="{{ $this->consoleUrl() }}" class="text-xs text-primary-600 hover:underline dark:text-primary-400">
                למסוף המלא ←
            </a>
        </x-slot>

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
