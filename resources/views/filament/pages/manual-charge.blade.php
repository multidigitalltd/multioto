<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-shield-check">
        <x-slot name="heading">איך זה עובד</x-slot>
        <ul class="list-disc pr-5 text-sm text-gray-500 dark:text-gray-400 space-y-1">
            <li>החיוב מתבצע מול <strong>הכרטיס השמור</strong> של הלקוח (טוקן) — מספרי כרטיס לעולם אינם עוברים דרך המערכת.</li>
            <li>אם ללקוח עדיין אין כרטיס שמור: בעמוד <strong>לקוחות</strong> → <strong>"העתקת קישור לכרטיס"</strong>, הלקוח מזין כרטיס בעמוד המאובטח של קארדקום, ואז הוא יופיע כאן.</li>
            <li>החיוב רץ ברקע. התוצאה (הצליח/נכשל + מזהה עסקה) מופיעה בעמוד <strong>חיובים</strong>, והחשבונית בעמוד <strong>חשבוניות</strong>.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
