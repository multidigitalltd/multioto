<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-information-circle">
        <x-slot name="heading">איך זה עובד</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            מדי לילה רץ ניקוי אוטומטי שמוחק רשומות ישנות מהמשך שנקבע כאן, כדי שמסד הנתונים יישאר רזה ומהיר.
            הניקוי משפיע רק על היסטוריה — נתוני לקוחות, חיובים, חשבוניות ופניות <strong>אינם</strong> נמחקים לעולם.
            שינוי הערכים כאן נכנס לתוקף בניקוי הלילי הבא.
        </p>
    </x-filament::section>
</x-filament-panels::page>
