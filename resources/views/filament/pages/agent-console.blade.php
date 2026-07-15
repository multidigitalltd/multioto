<x-filament-panels::page>
    <form wire:submit="run" class="flex flex-col gap-3">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="run">שלח לסוכן</span>
                <span wire:loading wire:target="run">הסוכן חושב…</span>
            </x-filament::button>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                הסוכן מבין את ההוראה, מזהה את הפנייה או האתר, ומגיש פעולה לאישור. שום דבר לא נשלח ללקוח ולא מתבצע באתר ללא אישורכם.
            </p>
        </div>
    </form>

    {{-- A couple of ready-made examples to make the input obvious. --}}
    <x-filament::section icon="heroicon-o-light-bulb" collapsible collapsed>
        <x-slot name="heading">דוגמאות להוראות</x-slot>
        <ul class="list-disc space-y-1 pe-5 text-sm text-gray-600 dark:text-gray-300">
            <li>תענה למשה בכרטיס הפתוח שאנחנו על זה ונחזור אליו היום.</li>
            <li>תעדכן את הלקוח בפנייה 148 שהתקלה טופלה.</li>
            <li>תנקה קאש באתר example.co.il · תבדוק למה האתר של דנה איטי.</li>
            <li>תשלח דרישת תשלום למשה על 300 ש״ח עבור אחסון שנתי.</li>
            <li>תסמן תשלום בוצע לדנה · תשעה את האתר של רון · תפתח משימה להתקשר ליוסי.</li>
        </ul>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            אם חסר לסוכן מידע (למשל סכום) — הוא ישאל, ואפשר פשוט לענות בשורה הבאה כדי להמשיך.
        </p>
    </x-filament::section>

    {{-- Console history: what was asked and what came of it. --}}
    <x-filament::section icon="heroicon-o-clock">
        <x-slot name="heading">היסטוריית פקודות</x-slot>

        @forelse ($this->recentCommands as $command)
            <div @class([
                'flex flex-col gap-1 border-s-4 ps-3 py-2',
                'border-success-400' => $command->outcome->value === 'proposed',
                'border-info-400' => $command->outcome->value === 'dispatched',
                'border-warning-400' => $command->outcome->value === 'unclear',
                'border-danger-400' => $command->outcome->value === 'failed',
            ])>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-filament::badge :color="$command->outcome->getColor()">
                        {{ $command->outcome->getLabel() }}
                    </x-filament::badge>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $command->instruction }}</span>
                    <time class="text-xs text-gray-400" datetime="{{ $command->created_at->toIso8601String() }}">
                        {{ $command->created_at->format('d/m/Y H:i') }}
                    </time>
                </div>
                @if (filled($command->result))
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $command->result }}</p>
                @endif
                @if ($command->pending_action_id)
                    <a href="{{ \App\Filament\Resources\PendingActionResource::getUrl() }}"
                       class="text-xs text-primary-600 hover:underline dark:text-primary-400">
                        למסך האישורים ←
                    </a>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">עדיין לא ניתנו פקודות. כתבו הוראה למעלה כדי להתחיל.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
