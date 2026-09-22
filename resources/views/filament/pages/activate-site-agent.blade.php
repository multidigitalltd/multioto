<x-filament-panels::page>
    <form wire:submit="activate" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" size="lg">
            הפעלת הסוכן
        </x-filament::button>
    </form>
</x-filament-panels::page>
