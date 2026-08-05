<x-filament-panels::page>
    {{-- An empty screen used to mean two very different things: "you are up to
         date" and "nobody has checked in weeks". Only one of them is safe to
         act on, so when the check is stale or failed the page says so instead
         of quietly implying the first. --}}
    @if ($checkStale || $checkError)
        <x-filament::section icon="heroicon-o-exclamation-triangle" class="border-danger-300 dark:border-danger-700">
            <x-slot name="heading">בדיקת העדכונים אינה פועלת</x-slot>

            <div class="space-y-2 text-sm text-gray-700 dark:text-gray-200">
                @if ($checkError)
                    <p>
                        הבדיקה האחרונה נכשלה@if (! empty($lastCheck['at'])) ({{ $lastCheck['at'] }})@endif.
                        כל עוד היא נכשלת, המסך הזה לא יכול לדעת אם יש גרסה חדשה — היעדר הודעה כאן אינו אומר שאתם מעודכנים.
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-xs" dir="ltr">{{ $checkError }}</pre>
                    <p>ברוב המקרים זו הרשאת גישה למאגר בשרת (git fetch נכשל) — יש לוודא שהשרת יכול למשוך מהמאגר.</p>
                @elseif ($lastCheck === null)
                    <p>
                        סוכן העדכון בשרת מעולם לא רץ, ולכן אף פעם לא נבדק אם יש גרסה חדשה.
                        התקינו אותו פעם אחת בשרת:
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-xs" dir="ltr">bash docker/install-deploy-watcher.sh</pre>
                @else
                    <p>
                        הבדיקה האחרונה רצה ב-{{ $lastCheck['at'] }} ומאז שקט. הסוכן אמור לבדוק כל דקה,
                        ולכן כנראה הפסיק לרוץ. בדקו בשרת שה-cron פעיל:
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-xs" dir="ltr">crontab -l | grep deploy-watcher</pre>
                @endif

                <p class="text-gray-500 dark:text-gray-400">
                    בינתיים אפשר תמיד לעדכן ידנית: כפתור "עדכן עכשיו" למעלה פועל גם כשהבדיקה שבורה, וכך גם <code>./update.sh</code> בשרת.
                </p>
            </div>
        </x-filament::section>
    @endif

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
            </p>

            {{-- The highlights of the PENDING versions, extracted from the incoming
                 build's changelog by the host watcher — so the team sees why to
                 upgrade before installing. Falls back to a note when unavailable. --}}
            @if (! empty($available['releases']))
                <div class="mt-4">
                    <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">מה מחכה בעדכון הזה:</p>
                    <div class="flex flex-col gap-4">
                        @foreach ($available['releases'] as $release)
                            <div class="border-s-4 border-warning-400 ps-3">
                                <div class="mb-1 flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $release['title'] ?? '' }}</span>
                                    @if (! empty($release['version']))
                                        <span class="font-mono text-xs text-gray-400">v{{ $release['version'] }}</span>
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
                </div>
            @else
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    עיקרי היתרונות של הגרסה החדשה יופיעו כאן מיד לאחר ההתקנה.
                </p>
            @endif
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
                גרסה לא ידועה — סוכן העדכון בשרת מסמן אותה בכל סבב, כך שערך חסר כאן מעיד שהסוכן אינו רץ.
            </p>
        @endif

        {{-- Stands on its own, outside the version block: when the timestamp is
             the thing that is missing, hiding it behind another missing value
             leaves the screen saying nothing at all. --}}
        @if (! empty($lastCheck['at']))
            <div class="mt-2 text-sm">
                <span class="text-gray-500 dark:text-gray-400">נבדקו עדכונים:</span>
                <span class="font-semibold">{{ $lastCheck['at'] }}</span>
                @if (! $available && ! $checkStale && ! $checkError)
                    <span class="text-success-600 dark:text-success-400">· אתם מעודכנים</span>
                @endif
            </div>
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
