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
    @if ($scan !== null)
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">אבטחה — רכיבים פגיעים</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($scannedAt) נסרק: {{ \Illuminate\Support\Carbon::parse($scannedAt)->format('d/m/Y H:i') }} @endif
                </span>
            </div>

            @if (count($vulns) === 0)
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
    @endif

    {{-- Domain reputation: spam/malware blocklist status. --}}
    @php
        $rep = $site->reputation_scan ?? null;
        $listings = (array) data_get($rep, 'listings', []);
        $repCheckedAt = data_get($rep, 'checked_at');
    @endphp
    @if ($rep !== null)
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">מוניטין דומיין — רשימות חסימה</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($repCheckedAt) נבדק: {{ \Illuminate\Support\Carbon::parse($repCheckedAt)->format('d/m/Y H:i') }} @endif
                </span>
            </div>

            @if (count($listings) === 0)
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
    @endif

    {{-- DNS watch: the domain's A/MX/NS records + last detected change. --}}
    @php
        $dnsSnap = $site->dns_snapshot ?? null;
        $dnsRecords = (array) data_get($dnsSnap, 'records', []);
        $dnsCheckedAt = data_get($dnsSnap, 'checked_at');
        $dnsChangedAt = data_get($dnsSnap, 'changed_at');
        $dnsLabels = ['a' => 'A — כתובת האתר', 'mx' => 'MX — דואר', 'ns' => 'NS — שרתי שמות'];
    @endphp
    @if ($dnsSnap !== null)
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
                                <x-filament::badge :color="$check->is_up ? 'success' : 'danger'">
                                    {{ $check->is_up ? 'תקין' : 'נפילה' }}
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
</x-filament-panels::page>
