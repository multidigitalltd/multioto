<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($paymentUrl)
        <x-filament::section class="mt-6" icon="heroicon-o-lock-closed">
            <x-slot name="heading">תשלום מאובטח — קארדקום</x-slot>
            <x-slot name="description">הזינו את פרטי הכרטיס בחלון המאובטח של קארדקום. לאחר התשלום החיוב יתעדכן והחשבונית תופק אוטומטית. הכרטיס מוזן ישירות מול קארדקום ואינו עובר דרך המערכת.</x-slot>

            <div wire:ignore>
                <iframe
                    src="{{ $paymentUrl }}"
                    title="תשלום מאובטח קארדקום"
                    style="width:100%;height:74vh;border:0;border-radius:0.5rem;background:#fff"
                    allow="payment"
                ></iframe>
            </div>

            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                אם החלון לא נטען,
                <a href="{{ $paymentUrl }}" target="_blank" rel="noopener" class="text-primary-600 dark:text-primary-400 underline">פִּתחו את עמוד התשלום בכרטיסייה חדשה</a>,
                או העתיקו את הקישור ושִלחו ללקוח.
            </p>
        </x-filament::section>
    @endif

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
