<x-filament-panels::page>
    <div dir="rtl" class="space-y-6" @if ($active) wire:poll.5s="$refresh" @endif>
        <x-filament::section :heading="$suiteCounts['all'].' תרחישי שיחה עם הבוט'">
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <p>
                    הבדיקה מפעילה את ספק ה־AI והמודל שהוגדרו במערכת מול אתר WordPress וחנות WooCommerce מדומים.
                    היא כוללת שיחות המשך, אישורים, ביטולים ושחזור, ובודקת גם את הפעולות ואת הערכים שהשתנו.
                </p>
                <p class="font-medium">
                    הריצה צורכת שימוש בתשלום אצל ספק ה־AI. היא אינה משנה אתרי לקוחות, אינה שולחת הודעות ללקוחות ואינה מחייבת אותם.
                </p>
                @if ($provider === 'google')
                    <p>
                        מטמון Gemini מופעל בהרצות חדשות: ההנחיות הקבועות וקטלוג הכלים נשמרים לשימוש חוזר בין תרחישים וריצות,
                        בהתאם לתוקף ולהגדרות. היסטוריית השיחות ונתוני האתר המדומה אינם נשמרים במטמון המשותף.
                        יצירת המטמון, אחסונו והטוקנים בכל בקשה עשויים להיות מחויבים אצל הספק; מטמון אינו מבטל את העלות.
                        אם המטמון אינו זמין, ההרצה נעצרת כדי למנוע המשך סבב גדול ללא מטמון.
                    </p>
                @else
                    <p>המטמון המפורש המשותף בין תרחישי הבדיקה זמין עבור Gemini. אצל הספק שנבחר אין כאן הבטחה לשימוש במטמון.</p>
                @endif
                <p>
                    מעבר של תרחיש מעיד על עמידה בבדיקות שהוגדרו עבורו. זו אינה בדיקה של כל תוסף או מצב אפשרי באתר אמיתי,
                    ואין כאן סקירה אנושית של איכות כל תשובה. תרחיש שנכשל או נחסם נשאר מסומן כך בדוח.
                </p>
                <dl class="flex flex-wrap gap-x-6 gap-y-2">
                    <div><dt class="inline font-medium">ספק לריצה חדשה:</dt> <dd class="inline" dir="ltr">{{ $provider ?: 'לא הוגדר' }}</dd></div>
                    <div><dt class="inline font-medium">מודל:</dt> <dd class="inline" dir="ltr">{{ $model ?: 'לא הוגדר' }}</dd></div>
                </dl>
                @if (! $configured)
                    <p class="text-warning-700 dark:text-warning-300">
                        כדי להתחיל יש להפעיל את סוכן ה־AI ולהגדיר ספק, מודל ומפתח API
                        <a href="{{ \App\Filament\Pages\ManageAiAgent::getUrl() }}" class="font-medium underline">בהגדרות ה־AI</a>.
                    </p>
                @endif
                <div class="max-w-lg space-y-1">
                    <label for="evaluation-suite" class="block font-medium">תרחישים להרצה</label>
                    <select id="evaluation-suite" wire:model.live="suite" @disabled($active) aria-describedby="evaluation-suite-help" class="w-full rounded-lg border-gray-300 bg-white text-gray-950 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="original">{{ $suiteCounts['original'] }} התרחישים שנותרו מהסבב המקורי</option>
                        <option value="round2">{{ $suiteCounts['round2'] }} תרחישים חדשים — סבב שני</option>
                        <option value="all">כל {{ $suiteCounts['all'] }} התרחישים</option>
                    </select>
                    <p id="evaluation-suite-help" class="text-sm">התרחישים שכבר עברו בדוח שסופק הוסרו מהרצות חדשות. אפשר לבדוק את התרחישים שנותרו, את הסבב החדש או את שניהם יחד. דוחות קודמים שומרים על התוצאות והמספרים המקוריים שלהם.</p>
                </div>
                <x-filament::button wire:click="start" wire:loading.attr="disabled" :disabled="! $configured || $active" icon="heroicon-o-play">
                    הפעלת {{ $selectedCount }} התרחישים
                </x-filament::button>
                <span wire:loading wire:target="start" role="status">מוסיפים את הבדיקה לתור…</span>
            </div>
        </x-filament::section>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-danger-300 p-4 text-sm text-danger-700 dark:border-danger-700 dark:text-danger-300">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if ($run)
            @php
                $statuses = [
                    'queued' => 'ממתינה בתור',
                    'running' => 'הבדיקה מתבצעת',
                    'cancel_requested' => 'ממתינה לסיום התרחיש הנוכחי ולעצירה',
                    'completed' => 'הריצה הסתיימה',
                    'failed' => 'הריצה נעצרה עקב תקלה',
                    'canceled' => 'הריצה הופסקה לבקשת מנהל',
                ];
                $completed = (int) ($run['completed'] ?? 0);
                $total = max(1, (int) ($run['total'] ?? $selectedCount));
            @endphp
            <x-filament::section heading="הריצה האחרונה">
                <div class="space-y-4">
                    <div role="status" aria-live="polite" class="space-y-2">
                        <p class="font-semibold">{{ $statuses[$run['status']] ?? 'מצב הריצה אינו ידוע' }}</p>
                        <p class="text-sm">הושלמו {{ $completed }} מתוך {{ $total }} תרחישים.</p>
                        <progress value="{{ $completed }}" max="{{ $total }}" aria-label="התקדמות בדיקת התרחישים" class="h-3 w-full">{{ $completed }} / {{ $total }}</progress>
                    </div>

                    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @foreach (['passed' => 'עברו את הבדיקות המוגדרות', 'failed' => 'נכשלו בבדיקה', 'blocked' => 'נחסמו ולא נבדקו במלואם'] as $key => $label)
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-2xl font-semibold">{{ (int) ($run[$key] ?? 0) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <h2 class="font-semibold">מטמון וטוקנים בריצה זו</h2>
                        <p class="text-sm">{{ $cacheStatus }}</p>
                        @if (array_key_exists('cached_input_tokens', $run))
                            @php
                                $inputTokens = max(0, (int) ($run['input_tokens'] ?? 0));
                                $cachedTokens = max(0, (int) $run['cached_input_tokens']);
                            @endphp
                            <dl class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                                <div><dt class="inline font-medium">מצב המטמון המפורש שהוגדר לריצה:</dt> <dd class="inline">{{ ($run['cache_enabled'] ?? false) ? 'מופעל' : 'כבוי' }}</dd></div>
                                <div><dt class="inline font-medium">סך טוקני הקלט שדווחו:</dt> <dd class="inline" dir="ltr">{{ number_format($inputTokens) }}</dd></div>
                                <div><dt class="inline font-medium">טוקני קלט מהמטמון שאושרו על ידי הספק:</dt> <dd class="inline" dir="ltr">{{ number_format($cachedTokens) }}</dd></div>
                                <div><dt class="inline font-medium">טוקני קלט שלא דווחו כמטמון:</dt> <dd class="inline" dir="ltr">{{ number_format(max(0, (int) ($run['uncached_input_tokens'] ?? 0))) }}</dd></div>
                                @if ($inputTokens > 0)
                                    <div><dt class="inline font-medium">חלק המטמון מתוך טוקני הקלט:</dt> <dd class="inline" dir="ltr">{{ number_format(100 * $cachedTokens / $inputTokens, 1) }}%</dd></div>
                                @else
                                    <div>טרם נמדדו טוקני קלט.</div>
                                @endif
                                <div><dt class="inline font-medium">בקשות עם טוקני מטמון שאושרו:</dt> <dd class="inline" dir="ltr">{{ number_format(max(0, (int) ($run['cache_hit_requests'] ?? 0))) }}</dd></div>
                                <div><dt class="inline font-medium">קריאות לניהול המטמון / תשובות שהתקבלו:</dt> <dd class="inline" dir="ltr">{{ number_format(max(0, (int) ($run['cache_management_requests'] ?? 0))) }} / {{ number_format(max(0, (int) ($run['cache_management_responses'] ?? 0))) }}</dd></div>
                            </dl>
                            <p class="text-xs text-gray-500 dark:text-gray-400">המדדים מצטברים מהתשובות שנאספו עד כה. חלק הטוקנים שנקראו מהמטמון אינו אחוז החיסכון הכספי; העלות תלויה במחירי הקלט, הפלט והמטמון אצל הספק.</p>
                        @endif
                    </div>

                    <dl class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <div><dt class="inline font-medium">קבוצת תרחישים:</dt> <dd class="inline">{{ ['original' => 'הסבב המקורי', 'round2' => 'הסבב החדש', 'all' => 'שני הסבבים'][$run['suite'] ?? 'original'] ?? 'לא ידועה' }}</dd></div>
                        <div><dt class="inline font-medium">ספק ומודל בריצה זו:</dt> <dd class="inline" dir="ltr">{{ $run['provider'] }} · {{ $run['model'] }}</dd></div>
                        <div><dt class="inline font-medium">מזהה ריצה:</dt> <dd class="inline break-all" dir="ltr">{{ $run['id'] }}</dd></div>
                        @if ($run['current_case'] ?? null)
                            <div><dt class="inline font-medium">תרחיש נוכחי:</dt> <dd class="inline" dir="ltr">{{ $run['current_case'] }}</dd></div>
                        @endif
                        @foreach (['created_at' => 'נוצרה', 'updated_at' => 'עדכון אחרון', 'finished_at' => 'הסתיימה'] as $key => $label)
                            @if ($run[$key] ?? null)
                                <div><dt class="inline font-medium">{{ $label }}:</dt> <dd class="inline" dir="ltr">{{ \Illuminate\Support\Carbon::parse($run[$key])->timezone(config('app.timezone'))->format('d/m/Y H:i:s T') }}</dd></div>
                            @endif
                        @endforeach
                    </dl>

                    @if ($run['reason'] ?? null)
                        <p role="alert" class="text-sm text-warning-700 dark:text-warning-300">{{ $run['reason'] }}</p>
                    @endif
                    @if ($run['status'] === 'completed')
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            הריצה הסתיימה. מספר התרחישים שעברו מוצג בנפרד; הסיום כשלעצמו אינו מעיד שכל התרחישים עברו.
                        </p>
                    @endif
                    <div class="flex flex-wrap gap-3">
                        @if (in_array($run['status'], ['queued', 'running'], true))
                            <x-filament::button color="warning" wire:click="cancel('{{ $run['id'] }}')" wire:loading.attr="disabled" icon="heroicon-o-stop">
                                עצירה אחרי התרחיש הנוכחי
                            </x-filament::button>
                        @endif
                        <x-filament::button tag="a" color="gray" :href="route('site-agent.evaluation.download', ['run' => $run['id']])" :spa-mode="false" icon="heroicon-o-arrow-down-tray">
                            הורדת דוח JSON
                        </x-filament::button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">הדוח כולל את התרחישים שנבדקו ואת תוצאותיהם עד למועד ההורדה. בזמן הריצה הוא חלקי.</p>
                </div>
            </x-filament::section>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">טרם הופעלה בדיקה. הריצה תתחיל רק בלחיצה על כפתור ההפעלה.</p>
        @endif
    </div>
</x-filament-panels::page>
