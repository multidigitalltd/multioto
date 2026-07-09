<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-shield-check">
        <x-slot name="heading">איך זה עובד</x-slot>
        <ul class="list-disc pr-5 text-sm text-gray-500 dark:text-gray-400 space-y-1">
            <li><strong>לקוח עם כרטיס שמור</strong> (מסומן "כרטיס שמור ✓") — יחויב מיד, והחשבונית תופק בלינט אוטומטית.</li>
            <li><strong>לקוח ללא כרטיס, או לקוח חדש</strong> — ייווצר עמוד תשלום מאובטח של קארדקום. אפשר לפתוח אותו כאן ולהזין כרטיס, או להעתיק את הקישור ולשלוח ללקוח. לאחר התשלום החשבונית מופקת אוטומטית.</li>
            <li>מספרי כרטיס לעולם אינם עוברים דרך המערכת — הכרטיס מוזן רק בעמוד המאובטח של קארדקום.</li>
            <li>התוצאה מופיעה בעמוד <strong>חיובים</strong>, והמסמך בעמוד <strong>חשבוניות</strong>.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
