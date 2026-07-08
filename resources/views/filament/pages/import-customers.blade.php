<x-filament-panels::page>
    <form wire:submit="import">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" size="lg" icon="heroicon-o-arrow-up-tray">
                ייבוא הלקוחות
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
