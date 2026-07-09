<x-filament-panels::page>
    @if ($statusText)
        @php
            $variantClasses = match ($statusVariant) {
                'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400',
                'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400',
                default => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400',
            };
        @endphp
        <div
            role="status"
            aria-live="polite"
            wire:key="integration-status"
            class="rounded-lg px-4 py-3 text-sm font-medium ring-1 ring-inset {{ $variantClasses }}"
        >
            {{ $statusText }}
        </div>
    @endif

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-lock-closed">
        <x-slot name="heading">אבטחה</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            כל המפתחות נשמרים <strong>מוצפנים</strong> במסד הנתונים ואינם מוצגים חזרה בטופס.
            לכל אינטגרציה כפתור שמירה משלה — שמירה של ספק אחד לא נוגעת באחרים.
            שדה שנשאר ריק לא משנה את הערך הקיים. ניתן להמשיך ולהגדיר מפתחות גם דרך קובץ ה-<code>.env</code> —
            ערך שמוזן כאן גובר עליו. עם השמירה, המערכת בודקת אוטומטית שהחיבור לספק תקין ומציגה את התוצאה.
        </p>
    </x-filament::section>
</x-filament-panels::page>
