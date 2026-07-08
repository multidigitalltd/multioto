<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-cube">
        <x-slot name="heading">הגרסה הנוכחית</x-slot>

        @if ($version)
            <div class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400">מזהה גרסה:</span>
                    <span class="font-mono font-semibold">{{ $version['short'] ?? \Illuminate\Support\Str::limit($version['sha'] ?? '—', 8, '') }}</span>
                </div>
                @if (! empty($version['date']))
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">עודכן לאחרונה:</span>
                        <span class="font-semibold">{{ $version['date'] }}</span>
                    </div>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                גרסה לא ידועה עדיין — תסומן אוטומטית לאחר העדכון הבא.
            </p>
        @endif
    </x-filament::section>

    @if ($pending)
        <x-filament::section icon="heroicon-o-clock" class="mt-6">
            <x-slot name="heading">עדכון בתהליך</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                התבקש עדכון — הוא מתבצע כעת ברקע. רעננו את הסטטוס בעוד רגע.
            </p>
        </x-filament::section>
    @endif

    @if ($lastStatus)
        <x-filament::section icon="heroicon-o-information-circle" class="mt-6">
            <x-slot name="heading">העדכון האחרון</x-slot>
            <div class="text-sm">
                <span @class([
                    'font-semibold',
                    'text-success-600 dark:text-success-400' => ($lastStatus['state'] ?? '') === 'success',
                    'text-danger-600 dark:text-danger-400' => ($lastStatus['state'] ?? '') === 'failed',
                ])>
                    {{ ($lastStatus['state'] ?? '') === 'success' ? '✓ הצליח' : (($lastStatus['state'] ?? '') === 'failed' ? '✗ נכשל' : $lastStatus['state'] ?? '') }}
                </span>
                @if (! empty($lastStatus['at']))
                    <span class="text-gray-500 dark:text-gray-400">· {{ $lastStatus['at'] }}</span>
                @endif
                @if (! empty($lastStatus['message']))
                    <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $lastStatus['message'] }}</p>
                @endif
            </div>
        </x-filament::section>
    @endif

    @unless ($configured)
        <x-filament::section icon="heroicon-o-wrench-screwdriver" class="mt-6">
            <x-slot name="heading">הפעלת עדכון מהממשק</x-slot>
            <div class="text-sm text-gray-500 dark:text-gray-400 space-y-2">
                <p>
                    כדי לאפשר עדכון בלחיצה מהממשק, יש להפעיל פעם אחת את סוכן העדכון בשרת
                    (מריץ את <code>update.sh</code> באופן מבוקר כשמתבקש עדכון):
                </p>
                <pre class="overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-xs" dir="ltr">bash docker/install-deploy-watcher.sh</pre>
                <p>עד להפעלה, ניתן לעדכן ידנית בשרת עם <code>./update.sh</code>.</p>
            </div>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
