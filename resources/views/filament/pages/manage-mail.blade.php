<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($identities !== null)
        <x-filament::section class="mt-6" icon="heroicon-o-check-badge">
            <x-slot name="heading">שולחים מאומתים ב-Postmark</x-slot>
            <x-slot name="description">רק כתובת שמופיעה כאן כ"מאומתת", או כתובת תחת דומיין מאומת, תתקבל כשולח.</x-slot>

            @if (count($identities['senders']) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-right text-gray-500 dark:text-gray-400">
                                <th class="py-1 pl-4">כתובת</th>
                                <th class="py-1 pl-4">שם</th>
                                <th class="py-1">סטטוס</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($identities['senders'] as $sender)
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-1 pl-4 font-mono" dir="ltr">{{ $sender['email'] }}</td>
                                    <td class="py-1 pl-4">{{ $sender['name'] ?: '—' }}</td>
                                    <td class="py-1">
                                        @if ($sender['confirmed'])
                                            <span class="text-success-600 dark:text-success-400">✓ מאומת</span>
                                        @else
                                            <span class="text-warning-600 dark:text-warning-400">✗ ממתין לאימות</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">לא נמצאו כתובות שולח (Sender Signatures) בחשבון.</p>
            @endif

            @if (count($identities['domains']) > 0)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                    <strong>דומיינים מאומתים:</strong>
                    <span dir="ltr">{{ implode(', ', $identities['domains']) }}</span>
                    — כל כתובת תחת דומיין מאומת תתקבל כשולח.
                </p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section class="mt-6" icon="heroicon-o-lock-closed">
        <x-slot name="heading">אבטחה</x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            מפתחות Postmark נשמרים <strong>מוצפנים</strong> ואינם מוצגים חזרה בטופס. שדה שנשאר ריק לא משנה מפתח קיים.
            כתובת ושם השולח אינם סוד וניתן לערוך אותם ישירות. עם שמירת Server Token, המערכת מפעילה אוטומטית את
            Postmark כשרת המייל הפעיל ובודקת שהחיבור תקין.
        </p>
    </x-filament::section>
</x-filament-panels::page>
