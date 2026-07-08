<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                שמירת מפתחות
            </x-filament::button>
        </div>
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-lock-closed">
        <x-slot name="heading">אבטחה</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            כל המפתחות נשמרים <strong>מוצפנים</strong> במסד הנתונים ואינם מוצגים חזרה בטופס.
            שדה שנשאר ריק לא משנה את הערך הקיים. ניתן להמשיך ולהגדיר מפתחות גם דרך קובץ ה-<code>.env</code> —
            ערך שמוזן כאן גובר עליו.
        </p>
    </x-filament::section>
</x-filament-panels::page>
