<x-filament-panels::page>
    @if ($available)
        <x-filament::section icon="heroicon-o-arrow-up-circle" class="border-warning-300 dark:border-warning-700">
            <x-slot name="heading">עדכון זמין</x-slot>
            <p class="text-sm text-gray-700 dark:text-gray-200">
                יש {{ $available['behind'] }} עדכונים חדשים
                @if (! empty($available['short'])) (עד גרסה <span class="font-mono">{{ $available['short'] }}</span>) @endif
                שממתינים להתקנה.
                @if ($configured)
                    לחצו "עדכן עכשיו" למעלה כדי להתקין.
                @else
                    עדכנו בשרת עם <code>./update.sh</code>.
                @endif
                עיקרי היתרונות של הגרסה החדשה יופיעו כאן ("מה חדש") מיד לאחר ההתקנה.
            </p>
        </x-filament::section>
    @endif

    {{-- What's new: the highlights of the versions that are already installed
         (the changelog ships inside the running build). --}}
    @if ($this->releases->isNotEmpty())
        <x-filament::section icon="heroicon-o-sparkles">
            <x-slot name="heading">מה חדש</x-slot>
            <x-slot name="description">עיקרי היתרונות של הגרסאות המותקנות — האחרונה למעלה.</x-slot>

            <div class="flex flex-col gap-5">
                @foreach ($this->releases as $index => $release)
                    <div @class(['border-s-4 ps-3', 'border-primary-400' => $index === 0, 'border-gray-200 dark:border-gray-700' => $index !== 0])>
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            @if ($index === 0)
                                <x-filament::badge color="primary">האחרונה</x-filament::badge>
                            @endif
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $release['title'] ?? '' }}</span>
                            <span class="font-mono text-xs text-gray-400">v{{ $release['version'] }}</span>
                            @if (! empty($release['date']))
                                <span class="text-xs text-gray-400">· {{ $release['date'] }}</span>
                            @endif
                        </div>
                        <ul class="list-disc space-y-1 pe-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ((array) ($release['highlights'] ?? []) as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

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

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
