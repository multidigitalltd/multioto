<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-information-circle">
        <x-slot name="heading">איך זה עובד</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            חסימת השבת/חג <strong>אוטומטית</strong>: הזמנים מחושבים לפי לוח השנה והמיקום שלמעלה, בלי צורך לסמן
            שבתות או חגים ידנית. ימי שירות מיוחדים (מתכונת מצומצמת / דחוף בלבד) מסומנים לעומת זאת ידנית —
            מתוך "לוח שנה" תחת ניהול — והמתג כאן קובע אם הם משפיעים על המענה ללקוח.
        </p>
    </x-filament::section>
</x-filament-panels::page>
