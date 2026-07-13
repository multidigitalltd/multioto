<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check">
                שמירת הגדרות הסוכן
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-signal"
                                wire:click="testConnection" wire:loading.attr="disabled">
                בדיקת חיבור לספק
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-academic-cap"
                                wire:click="learnFromHistory" wire:loading.attr="disabled">
                למד מתשובות קודמות
            </x-filament::button>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            שמרו את ההגדרות ואז לחצו "בדיקת חיבור" כדי לוודא שהמפתח, הספק והדגם עובדים. אם נכשל — פרטי השגיאה נכתבים ליומן המערכת.
        </p>
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-shield-check">
        <x-slot name="heading">בטיחות</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            כל תשובה שהסוכן מנסח נשמרת כ<strong>טיוטה פנימית</strong> וממתינה לאישור אנושי לפני שליחה —
            הסוכן לעולם לא פונה ללקוח בעצמו. פעולות קריאה (סטטוס אתר, מידע חיוב) רצות אוטומטית;
            פעולות שינוי (קישור לעדכון כרטיס) מתווספות לטיוטה ומגיעות ללקוח רק אם תאשרו.
            המפתח נשמר מוצפן ואינו מוצג חזרה.
        </p>
    </x-filament::section>
</x-filament-panels::page>
