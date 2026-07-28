<x-filament-panels::page>
    @php
        $site = $this->record;
        $stats = $this->stats;
        $warnDays = (int) config('billing.monitoring.ssl_warn_days', 14);
        $slowMs = (int) config('billing.monitoring.slow_response_ms', 4000);
        $ssl = $site->ssl_days_left;
        $isDown = (bool) $site->openIncident;
        $domainWarnDays = (int) config('billing.monitoring.domain_warn_days', 30);
        $domainExpiry = $site->domain_expiry_at;
        $domainDaysLeft = $domainExpiry !== null
            ? (int) ceil(now()->startOfDay()->diffInDays($domainExpiry, false))
            : null;
    @endphp

    {{-- Context strip: site + customer + live state. --}}
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="font-semibold">{{ $site->domain }}</span>
        <x-filament::badge :color="$isDown ? 'danger' : 'success'">
            {{ $isDown ? 'לא זמין' : 'זמין' }}
        </x-filament::badge>
        @if ($site->customer)
            <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('view', ['record' => $site->customer]) }}"
               class="text-primary-600 hover:underline">{{ $site->customer->name }} ←</a>
        @endif
    </div>

    {{-- Stat cards: uptime, response time, SSL, domain — the whole health picture.
         The Filament panel's compiled CSS doesn't ship the sm:/lg:grid-cols
         responsive utilities, so an auto-fit template (independent of Tailwind's
         breakpoint classes) is used to pack the four cards onto one row when there
         is room and wrap down gracefully on narrow screens. --}}
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));" wire:poll.30s>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">זמינות ({{ $this->getStatsWindowDays() }} ימים)</div>
            <div class="mt-1 text-2xl font-bold">
                {{ $stats['uptime'] !== null ? $stats['uptime'] . '%' : '—' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $stats['up'] }} / {{ $stats['total'] }} בדיקות תקינות
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">זמן תגובה ממוצע</div>
            <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-amber-600 dark:text-amber-400' => $stats['avg_ms'] !== null && $stats['avg_ms'] >= $slowMs,
                ])>
                {{ $stats['avg_ms'] !== null ? number_format($stats['avg_ms']) . ' ms' : '—' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($stats['avg_ms'] !== null && $stats['avg_ms'] >= $slowMs) איטי מהרגיל @else תקין @endif
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">תעודת SSL</div>
            <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $ssl !== null && $ssl <= 0,
                    'text-amber-600 dark:text-amber-400' => $ssl !== null && $ssl > 0 && $ssl <= $warnDays,
                ])>
                @if ($ssl === null) — @elseif ($ssl <= 0) פגה @else {{ $ssl }} ימים @endif
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($ssl !== null && $ssl > 0 && $ssl <= $warnDays) עומדת לפוג — מומלץ לחדש @else נותרו עד לחידוש @endif
            </div>
        </div>

        {{-- Domain registration expiry — until now only surfaced in the team
             email; now visible here so the team (and, via the reminder button,
             the customer) can act before the domain lapses. --}}
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">תוקף הדומיין</div>
            <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $domainDaysLeft !== null && $domainDaysLeft <= 0,
                    'text-amber-600 dark:text-amber-400' => $domainDaysLeft !== null && $domainDaysLeft > 0 && $domainDaysLeft <= $domainWarnDays,
                ])>
                @if ($domainDaysLeft === null) — @elseif ($domainDaysLeft <= 0) פג @else {{ $domainDaysLeft }} ימים @endif
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($domainExpiry !== null)
                    יפוג ב-{{ $domainExpiry->format('d/m/Y') }}
                @else
                    לא נבדק עדיין
                @endif
                @if (filled($site->domain_registrant))
                    <br>בעלים: {{ $site->domain_registrant }}
                @endif
            </div>
        </div>
    </div>

    {{-- Security scan: known-vulnerable installed components (Wordfence feed). --}}
    @php
        $scan = $site->vulnerability_scan ?? null;
        $vulns = (array) data_get($scan, 'items', []);
        $scannedAt = data_get($scan, 'scanned_at');
        $sevColor = fn (?string $s) => match (strtolower((string) $s)) {
            'critical' => 'danger',
            'high' => 'danger',
            'medium' => 'warning',
            'low' => 'gray',
            default => 'gray',
        };
    @endphp
    @php
        $scanRunStatus = data_get($scan, 'last_run_status');
        $scanRunAt = data_get($scan, 'last_run_at');
        $scanFailReason = match ($scanRunStatus) {
            'unreadable' => 'לא ניתן היה לקרוא את רשימת הרכיבים מהתוסף — ודאו שהתוסף באתר מעודכן ומחובר ("בדוק חיבור AI").',
            'feed_unavailable' => 'פיד הפגיעויות ('.(config('security.vulnerabilities.source', 'wordfence') === 'wpscan' ? 'WPScan' : 'Wordfence').') לא היה זמין — ננסה שוב בריצה הבאה.',
            default => null,
        };
    @endphp
    {{-- Always rendered: an operator must SEE that no scan has completed yet —
         a missing card reads as "the feature doesn't exist". --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">אבטחה — רכיבים פגיעים</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($scannedAt) נסרק: {{ \Illuminate\Support\Carbon::parse($scannedAt)->format('d/m/Y H:i') }} @endif
                </span>
            </div>

            @if ($scanFailReason !== null)
                <div class="mb-3 flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400" role="status">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                    <span>
                        הבדיקה האחרונה
                        @if ($scanRunAt) ({{ \Illuminate\Support\Carbon::parse($scanRunAt)->format('d/m/Y H:i') }}) @endif
                        לא הושלמה: {{ $scanFailReason }}
                    </span>
                </div>
            @endif

            @if (count($vulns) === 0 && $scannedAt === null)
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    טרם הושלמה סריקה מלאה לאתר הזה.
                    @if (! $site->mcp_enabled)
                        הסריקה דורשת חיבור AI פעיל לאתר (התוסף מותקן ו"חיבור פעיל" דלוק).
                    @else
                        לחצו "סריקת אבטחה" למעלה או המתינו לריצה היומית; אם אין תוצאה — בדקו בניהול ← יומן אירועים.
                    @endif
                </div>
            @elseif (count($vulns) === 0)
                <div class="flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
                    <x-heroicon-o-shield-check class="h-5 w-5" />
                    לא נמצאו רכיבים פגיעים ידועים.
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($vulns as $v)
                        <div class="rounded-lg border border-gray-100 p-3 text-sm dark:border-gray-700">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium">
                                    {{ $v['name'] ?? $v['slug'] ?? '' }}
                                    <span class="text-gray-500">{{ $v['version'] ?? '' }}</span>
                                </span>
                                @if (filled($v['severity'] ?? null))
                                    <x-filament::badge :color="$sevColor($v['severity'])">{{ $v['severity'] }}</x-filament::badge>
                                @endif
                            </div>
                            <div class="mt-1 text-gray-700 dark:text-gray-300">{{ $v['title'] ?? '' }}</div>
                            <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                @if (filled($v['patched_in'] ?? null)) <span>תוקן בגרסה {{ $v['patched_in'] }}</span> @endif
                                @if (filled($v['cve'] ?? null)) <span>{{ $v['cve'] }}</span> @endif
                                @if (filled($v['link'] ?? null)) <a href="{{ $v['link'] }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">פרטים</a> @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
    </div>

    {{-- Domain reputation: spam/malware blocklist status. --}}
    @php
        $rep = $site->reputation_scan ?? null;
        $listings = (array) data_get($rep, 'listings', []);
        $repCheckedAt = data_get($rep, 'checked_at');
    @endphp
    @php
        $repRunStatus = data_get($rep, 'last_run_status');
        $repRunAt = data_get($rep, 'last_run_at');
    @endphp
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">מוניטין דומיין — רשימות חסימה</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($repCheckedAt) נבדק: {{ \Illuminate\Support\Carbon::parse($repCheckedAt)->format('d/m/Y H:i') }} @endif
                </span>
            </div>

            {{-- Which sources actually answered in the last run — the "is my
                 key working?" indicator, straight on the card. After a
                 no-source run the stored sources are stale, so the warning
                 below speaks alone instead of a misleading "רץ ✓". --}}
            @php $repSources = (array) data_get($rep, 'sources', []); @endphp
            @if ($repSources !== [] && $repRunStatus !== 'no_source')
                <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    @foreach (['urlhaus' => 'URLhaus', 'spamhaus' => 'Spamhaus', 'safe_browsing' => 'Google Safe Browsing'] as $srcKey => $srcLabel)
                        @if (array_key_exists($srcKey, $repSources))
                            <span @class(['text-success-600 dark:text-success-400' => $repSources[$srcKey], 'text-amber-600 dark:text-amber-400' => ! $repSources[$srcKey]])>
                                {{ $srcLabel }}: {{ $repSources[$srcKey] ? 'רץ ✓' : 'לא רץ ✗' }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif

            @if ($repRunStatus === 'no_source')
                <div class="mb-3 flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400" role="status">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                    <span>
                        הבדיקה האחרונה
                        @if ($repRunAt) ({{ \Illuminate\Support\Carbon::parse($repRunAt)->format('d/m/Y H:i') }}) @endif
                        לא הושלמה: אף מקור חיצוני (URLhaus / Spamhaus) לא היה זמין — ייתכן שהשרת חוסם בקשות יוצאות. פרטים ב"יומן אירועים".
                    </span>
                </div>
            @endif

            @if (count($listings) === 0 && $repCheckedAt === null)
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    טרם הושלמה בדיקת מוניטין לדומיין הזה.
                    @if (blank($site->domain))
                        לאתר לא מוגדר דומיין — הבדיקה דורשת דומיין.
                    @else
                        לחצו "בדיקת מוניטין" למעלה או המתינו לריצה היומית; אם אין תוצאה — בדקו בניהול ← יומן אירועים.
                    @endif
                </div>
            @elseif (count($listings) === 0)
                <div class="flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
                    <x-heroicon-o-check-badge class="h-5 w-5" />
                    הדומיין נקי — לא נמצא ברשימות ספאם/נוזקות.
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($listings as $l)
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-danger-100 bg-danger-50 p-3 text-sm dark:border-danger-900/40 dark:bg-danger-900/10">
                            <span class="font-medium">
                                {{ ($l['type'] ?? '') === 'spam' ? '📧' : '🦠' }}
                                {{ $l['source'] ?? '' }} — {{ $l['detail'] ?? '' }}
                            </span>
                            @if (filled($l['link'] ?? null))
                                <a href="{{ $l['link'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-primary-600 hover:underline dark:text-primary-400">פרטים</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
    </div>

    {{-- Defacement watch: homepage content fingerprint state. --}}
    @php
        $content = $site->content_snapshot ?? null;
        $contentSuspected = (bool) data_get($content, 'suspected', false);
        $contentSimilarity = data_get($content, 'similarity');
        $contentCheckedAt = data_get($content, 'checked_at');
        $contentAlertedAt = data_get($content, 'alerted_at');
    @endphp
    @if ($content === null)
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <h3 class="mb-3 text-sm font-semibold">זיהוי השחתה — תוכן דף הבית</h3>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{-- Name the real blocker: a site the scheduler/job skips would
                     otherwise show "runs every morning" forever. --}}
                @if (blank($site->domain))
                    לא מוגדר דומיין לאתר — זיהוי השחתה דורש דומיין.
                @elseif (! $site->monitor_enabled)
                    הניטור לאתר כבוי — הפעילו ניטור כדי שבדיקת ההשחתה תרוץ.
                @else
                    טרם נלקחה טביעת תוכן לדף הבית. הבדיקה רצה אוטומטית כל בוקר, או מייד דרך "עוד כלים" ← "בדיקת השחתה".
                @endif
            </div>
        </div>
    @else
        <div @class([
            'rounded-xl p-4 shadow-sm',
            'bg-white dark:bg-gray-800' => ! $contentSuspected,
            'border border-danger-300 bg-danger-50 dark:border-danger-800 dark:bg-danger-900/10' => $contentSuspected,
        ])>
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">זיהוי השחתה — תוכן דף הבית</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($contentCheckedAt) נבדק: {{ \Illuminate\Support\Carbon::parse($contentCheckedAt)->format('d/m/Y H:i') }} @endif
                </span>
            </div>

            @if ($contentSuspected)
                <div class="flex items-start gap-2 text-sm text-danger-700 dark:text-danger-400">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                    <div>
                        <div class="font-semibold">חשד להשחתה — התוכן שונה באופן קיצוני מהבסיס המוכר.</div>
                        <div class="mt-1 space-y-0.5 text-xs">
                            @if ($contentSimilarity !== null)
                                <div>דמיון לתוכן המוכר: {{ $contentSimilarity }}%</div>
                            @endif
                            @if (filled(data_get($content, 'marker')))
                                <div>סימן פריצה שזוהה: "{{ data_get($content, 'marker') }}"</div>
                            @endif
                            @if (filled(data_get($content, 'suspected_title')))
                                <div>כותרת הדף כעת: "{{ data_get($content, 'suspected_title') }}"</div>
                            @endif
                            @if ($contentAlertedAt)
                                <div>הצוות הותרע: {{ \Illuminate\Support\Carbon::parse($contentAlertedAt)->format('d/m/Y H:i') }}</div>
                            @endif
                        </div>
                        <div class="mt-2 text-xs">
                            אם מדובר בעיצוב מחודש מכוון — השתמשו בכפתור <span class="font-semibold">"אשר את התוכן הנוכחי"</span> למעלה. אחרת, בדקו פריצה מיד.
                        </div>
                    </div>
                </div>
            @else
                <div class="flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                    התוכן תקין ותואם את הבסיס המוכר{{ $contentSimilarity !== null ? " (דמיון {$contentSimilarity}%)" : '' }}.
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 text-xs text-gray-500 dark:text-gray-400">
                    @if (filled(data_get($content, 'title')))
                        <span>כותרת: "{{ data_get($content, 'title') }}"</span>
                    @endif
                    <span>אורך תוכן: {{ number_format((int) data_get($content, 'length', 0)) }} תווים</span>
                </div>
            @endif
        </div>
    @endif

    {{-- DNS watch: the domain's A/MX/NS records + last detected change. --}}
    @php
        $dnsSnap = $site->dns_snapshot ?? null;
        $dnsRecords = (array) data_get($dnsSnap, 'records', []);
        $dnsCheckedAt = data_get($dnsSnap, 'checked_at');
        $dnsChangedAt = data_get($dnsSnap, 'changed_at');
        $dnsLabels = ['a' => 'A — כתובת האתר', 'mx' => 'MX — דואר', 'ns' => 'NS — שרתי שמות'];
    @endphp
    @if ($dnsSnap === null)
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <h3 class="mb-3 text-sm font-semibold">רשומות DNS — מעקב שינויים</h3>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                @if (blank($site->domain))
                    לא מוגדר דומיין לאתר — מעקב DNS דורש דומיין.
                @else
                    טרם נלקחה תמונת DNS לדומיין. הבדיקה רצה אוטומטית כל בוקר, או מייד דרך "עוד כלים" ← "בדיקת DNS".
                @endif
            </div>
        </div>
    @else
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">רשומות DNS — מעקב שינויים</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($dnsCheckedAt) נבדק: {{ \Illuminate\Support\Carbon::parse($dnsCheckedAt)->format('d/m/Y H:i') }} @endif
                </span>
            </div>

            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                @foreach ($dnsLabels as $type => $label)
                    <div class="rounded-lg border border-gray-100 p-3 text-sm dark:border-gray-700">
                        <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        @php $values = $dnsRecords[$type] ?? null; @endphp
                        @if ($values === null)
                            <span class="text-gray-400">לא נבדק</span>
                        @elseif ($values === [])
                            <span class="text-gray-400">אין רשומות</span>
                        @else
                            <ul class="space-y-0.5 font-mono text-xs" dir="ltr">
                                @foreach ($values as $value)
                                    <li>{{ $value }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                @if ($dnsChangedAt)
                    שינוי אחרון זוהה: {{ \Illuminate\Support\Carbon::parse($dnsChangedAt)->format('d/m/Y H:i') }}
                @else
                    לא זוהו שינויים מאז תחילת המעקב.
                @endif
            </div>
        </div>
    @endif

    {{-- Response-time trend — one bar per recent probe (oldest → newest). --}}
    @php $trend = $this->trend; @endphp
    @if (count($trend['points']) > 1)
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:poll.30s>
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">מגמת זמן תגובה</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">שיא: {{ number_format($trend['max']) }} ms</span>
            </div>
            <div class="flex items-end gap-0.5" style="height: 6rem;"
                 role="img"
                 aria-label="גרף מגמת זמני תגובה של {{ count($trend['points']) }} הבדיקות האחרונות. שיא {{ number_format($trend['max']) }} מילישניות.">
                @foreach ($trend['points'] as $point)
                    <div @class([
                            'flex-1 rounded-t',
                            'bg-danger-500' => ! $point['up'],
                            'bg-amber-500' => $point['up'] && $point['ms'] >= $slowMs,
                            'bg-primary-500' => $point['up'] && $point['ms'] < $slowMs,
                        ])
                        style="height: {{ max(3, $point['pct']) }}%;"
                        title="{{ $point['at']->format('d/m/Y H:i') }} — {{ $point['up'] ? number_format($point['ms']).' ms' : 'נפילה' }}"></div>
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-primary-500"></span> תקין</span>
                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span> איטי</span>
                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-danger-500"></span> נפילה</span>
            </div>
        </div>
    @endif

    {{-- Recent probes. Polls so a live outage/recovery updates in place. --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:poll.30s>
        <h3 class="mb-3 text-sm font-semibold">בדיקות אחרונות</h3>
        <div style="overflow-x: auto;">
            <table class="w-full text-sm">
                <caption class="sr-only">היסטוריית בדיקות ניטור אחרונות עבור {{ $site->domain }}</caption>
                <thead>
                    <tr class="text-xs text-gray-500 dark:text-gray-400">
                        <th scope="col" class="p-2 text-start font-medium">מתי</th>
                        <th scope="col" class="p-2 text-start font-medium">מצב</th>
                        <th scope="col" class="p-2 text-start font-medium">קוד HTTP</th>
                        <th scope="col" class="p-2 text-start font-medium">זמן תגובה</th>
                        <th scope="col" class="p-2 text-start font-medium">שגיאה</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->recentChecks as $check)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="p-2 whitespace-nowrap">
                                <time datetime="{{ $check->checked_at->toIso8601String() }}">{{ $check->checked_at->format('d/m/Y H:i') }}</time>
                            </td>
                            <td class="p-2">
                                @php
                                    // 401/403/429 while "up" = our probe was blocked
                                    // by bot protection — the site is likely fine for
                                    // visitors, but "תקין" next to a 403 misleads.
                                    $isProtected = $check->is_up && in_array($check->status_code, [401, 403, 429], true);
                                @endphp
                                <x-filament::badge :color="$check->is_up ? ($isProtected ? 'warning' : 'success') : 'danger'">
                                    {{ $check->is_up ? ($isProtected ? 'מוגן (חסימת בוט)' : 'תקין') : 'נפילה' }}
                                </x-filament::badge>
                            </td>
                            <td class="p-2">{{ $check->status_code ?? '—' }}</td>
                            <td @class([
                                    'p-2 whitespace-nowrap',
                                    'text-amber-600 dark:text-amber-400' => $check->response_ms >= $slowMs,
                                ])>{{ number_format($check->response_ms) }} ms</td>
                            <td class="p-2 text-gray-500 dark:text-gray-400">{{ $check->error ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-4 text-center text-gray-500">אין בדיקות ניטור עדיין.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Silent-failure watches: the two ways a site fails while answering 200.
         Always rendered, so an operator can tell "not yet checked" apart from
         "checked and fine". --}}
    <div class="grid gap-4 md:grid-cols-2">
        @php
            $pulse = $site->store_pulse ?? null;
            $pulseStatus = data_get($pulse, 'status');
        @endphp
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">דופק מכירות (חנות)</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if (data_get($pulse, 'checked_at'))
                        נבדק: {{ \Illuminate\Support\Carbon::parse(data_get($pulse, 'checked_at'))->format('d/m/Y H:i') }}
                    @endif
                </span>
            </div>

            @if ($pulseStatus === 'store_silent' || $pulseStatus === 'store_payments')
                <div class="flex items-start gap-2 text-sm text-danger-700 dark:text-danger-400" role="status">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                    <span>
                        @if ($pulseStatus === 'store_silent')
                            לא נוצרה אף הזמנה ב-24 השעות האחרונות (ממוצע יומי: {{ data_get($pulse, 'baseline_orders') }}). ייתכן שתהליך הרכישה שבור.
                        @else
                            נוצרו {{ data_get($pulse, 'orders_24h') }} הזמנות ואף אחת לא שולמה (ממוצע תשלומים יומי: {{ data_get($pulse, 'baseline_paid') }}). חשד לתקלת סליקה.
                        @endif
                    </span>
                </div>
            @elseif ($pulseStatus === 'ok')
                <p class="text-sm text-success-700 dark:text-success-400">
                    ✓ תקין — {{ data_get($pulse, 'orders_24h') }} הזמנות ב-24ש, מתוכן {{ data_get($pulse, 'paid_24h') }} שולמו (ממוצע יומי: {{ data_get($pulse, 'baseline_orders') }}).
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    רלוונטי לחנויות ווקומרס מחוברות. הבדיקה רצה כל בוקר ומזהה חנות שהפסיקה לקבל הזמנות למרות שהאתר עולה תקין.
                </p>
            @endif
        </div>

        @php
            $layout = $site->layout_snapshot ?? null;
            $layoutStatus = data_get($layout, 'status');
        @endphp
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">מבנה דף הבית</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if (data_get($layout, 'checked_at'))
                        נבדק: {{ \Illuminate\Support\Carbon::parse(data_get($layout, 'checked_at'))->format('d/m/Y H:i') }}
                    @endif
                </span>
            </div>

            @if ($layoutStatus === 'broken')
                <div class="flex items-start gap-2 text-sm text-danger-700 dark:text-danger-400" role="status">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                    <div>
                        <p>העמוד עולה תקין אך המבנה שלו נשבר:</p>
                        <ul class="mt-1 list-inside list-disc">
                            @foreach ((array) data_get($layout, 'reasons', []) as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-1 text-xs">אם התצוגה תקינה — לחצו "אשר את מבנה העמוד" בכפתורי הפעולה למעלה.</p>
                    </div>
                </div>
            @elseif ($layoutStatus === 'ok')
                <p class="text-sm text-success-700 dark:text-success-400">
                    ✓ המבנה תקין — {{ data_get($layout, 'fingerprint.images') }} תמונות, {{ data_get($layout, 'fingerprint.links') }} קישורים, הכותרת והתפריט במקומם.
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    הבדיקה היומית משווה את מבנה דף הבית (תמונות, קישורים, כותרת, תפריט) לצילום האחרון — ותופסת עדכון ששבר את התצוגה למרות שהאתר עונה תקין.
                </p>
            @endif
        </div>
    </div>

    {{-- Durable findings log: what we detected on this site and when. This is
         the record shown to the customer ("ב-27/07 זיהינו מנהל חדש"). --}}
    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold">יומן ממצאי אבטחה ושינויים</h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">מה זוהה באתר ומתי</span>
        </div>

        @php $events = $site->events()->latest('detected_at')->limit(30)->get(); @endphp

        @forelse ($events as $event)
            <div @class([
                'flex flex-wrap items-start gap-x-3 gap-y-1 border-b border-gray-100 py-2 text-sm last:border-0 dark:border-gray-700',
            ])>
                <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                    {{ $event->detected_at?->format('d/m/Y H:i') }}
                </span>
                <x-filament::badge :color="match ($event->severity) { 'critical' => 'danger', 'warning' => 'warning', default => 'gray' }">
                    {{ $event->label() }}
                </x-filament::badge>
                <span class="font-medium">{{ $event->title }}</span>
                @if ($event->detail)
                    <span class="w-full text-xs text-gray-500 dark:text-gray-400">{{ $event->detail }}</span>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">
                אין ממצאים רשומים לאתר הזה. ממצאים (מנהל חדש, תוסף/תבנית שנוספו או הוסרו, מוניטין, השחתה) יופיעו כאן עם התאריך שבו זוהו.
            </p>
        @endforelse
    </div>
</x-filament-panels::page>
