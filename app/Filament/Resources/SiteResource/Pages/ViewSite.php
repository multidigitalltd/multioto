<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Filament\Support\SiteActions;
use App\Jobs\DetectSiteTypeJob;
use App\Jobs\InvestigateSiteJob;
use App\Jobs\SendDomainRenewalReminderJob;
use App\Models\MonitorCheck;
use App\Services\Agent\SiteConnector;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Automation\ApprovalGate;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\System\OutboundIp;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Per-site monitoring history: current up/down state, uptime % and average
 * response time over the last week, TLS certificate days-left, and the most
 * recent probes. Read-only — all remediation goes through the approval gate.
 */
class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    protected static string $view = 'filament.sites.monitor';

    /**
     * The Cloudflare IP-rules result, fetched once when that modal is opened and
     * held here so the page's 30s wire:poll re-renders don't re-hit the API.
     *
     * @var array{ok: bool, message: string, rules: array<int, array<string, string>>}|null
     */
    public ?array $cloudflareRulesResult = null;

    /**
     * The site page is the single action hub: clicking a card lands here with
     * ALL the tools in the header — connection on/off, diagnostics, live test,
     * Cloudflare and plugin codes — while the edit form holds only settings.
     * Page actions use Filament\Actions, so they are declared here; the shared
     * logic (tool params, Cloudflare token) is reused from SiteActions.
     */
    protected function getHeaderActions(): array
    {
        $isAdmin = fn (): bool => auth()->user()?->isAdmin() ?? false;

        return [
            Actions\Action::make('diagnose')
                ->label('אבחון')
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->action(function (SiteDiagnostics $diagnostics): void {
                    try {
                        $result = $diagnostics->run($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('האבחון נכשל')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('אבחון '.$this->record->domain.($result['healthy'] ? ' — תקין ✓' : ' — נמצאו בעיות'))
                        ->body($result['summary'])
                        ->{$result['healthy'] ? 'success' : 'warning'}()
                        ->persistent()
                        ->send();
                }),

            // Connection on/off — makes the AI-connection state visible right on
            // the page (the toggle used to be buried in the edit form) and flips
            // it in one click. Enabling lets the model derive the endpoint.
            Actions\Action::make('toggleMcp')
                ->label(fn (): string => $this->record->mcp_enabled ? 'חיבור AI פעיל — כבה' : 'חיבור AI כבוי — הפעל')
                ->icon(fn (): string => $this->record->mcp_enabled ? 'heroicon-o-bolt' : 'heroicon-o-bolt-slash')
                ->color(fn (): string => $this->record->mcp_enabled ? 'success' : 'gray')
                ->visible($isAdmin)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->record->mcp_enabled ? 'כיבוי חיבור AI' : 'הפעלת חיבור AI')
                ->modalDescription(fn (): string => $this->record->mcp_enabled
                    ? 'הסוכן יפסיק להתחבר לאתר הזה. אפשר להפעיל שוב בכל עת.'
                    : 'הסוכן יוכל להתחבר לאתר. ודאו שקודי החיבור מוגדרים בתוסף ("קודי חיבור לתוסף").')
                ->modalSubmitActionLabel(fn (): string => $this->record->mcp_enabled ? 'כבה' : 'הפעל')
                ->action(function (): void {
                    $enabling = ! $this->record->mcp_enabled;
                    $this->record->update(['mcp_enabled' => $enabling]);

                    Notification::make()
                        ->title($enabling ? 'חיבור ה-AI הופעל' : 'חיבור ה-AI כובה')
                        ->body($enabling ? 'עכשיו אפשר ללחוץ "בדוק חיבור AI".' : null)
                        ->success()->send();
                }),

            // Send the customer a domain-renewal reminder — for the case where
            // the CUSTOMER, not us, renews the domain. Only shown once we know an
            // expiry date and the site is linked to a customer.
            Actions\Action::make('domainRenewalReminder')
                ->label('תזכורת חידוש דומיין ללקוח')
                ->icon('heroicon-o-bell-alert')
                ->color('warning')
                // Only when we know an expiry date, the site has a customer, AND
                // that customer has at least one reachable channel (email or a
                // WhatsApp JID/phone) — otherwise the reminder would silently
                // reach no one.
                ->visible(fn (): bool => $this->record->domain_expiry_at !== null
                    && $this->record->customer !== null
                    && (filled($this->record->customer->email) || filled($this->record->customer->whatsappRecipient())))
                ->requiresConfirmation()
                ->modalHeading('שליחת תזכורת חידוש דומיין')
                ->modalDescription(fn (): string => sprintf(
                    'תישלח ללקוח %s תזכורת שהדומיין %s יפוג ב-%s. בחרו באילו ערוצים לשלוח.',
                    $this->record->customer?->name ?? '',
                    $this->record->domain,
                    $this->record->domain_expiry_at?->format('d/m/Y') ?? '',
                ))
                // Pick the channels — only the ones the customer actually has are
                // offered, and all available ones are ticked by default.
                ->form(fn (): array => [
                    Forms\Components\CheckboxList::make('channels')
                        ->label('ערוצי שליחה')
                        ->options(array_filter([
                            'email' => filled($this->record->customer?->email) ? 'מייל'.' ('.$this->record->customer->email.')' : null,
                            'whatsapp' => filled($this->record->customer?->whatsappRecipient()) ? 'וואטסאפ' : null,
                        ]))
                        ->default(array_values(array_filter([
                            filled($this->record->customer?->email) ? 'email' : null,
                            filled($this->record->customer?->whatsappRecipient()) ? 'whatsapp' : null,
                        ])))
                        ->required()
                        ->bulkToggleable(),
                ])
                ->modalSubmitActionLabel('שלח תזכורת')
                ->action(function (array $data): void {
                    $channels = array_values(array_unique(array_filter((array) ($data['channels'] ?? []))));

                    if ($channels === []) {
                        Notification::make()->title('לא נבחר ערוץ')->body('בחרו לפחות ערוץ אחד.')->warning()->send();

                        return;
                    }

                    SendDomainRenewalReminderJob::dispatch($this->record->id, $channels);

                    $labels = implode(' + ', array_map(fn (string $c): string => $c === 'email' ? 'מייל' : 'וואטסאפ', $channels));
                    Notification::make()->title('התזכורת נשלחה ללקוח')
                        ->body("נשלחה ב: {$labels}.")
                        ->success()->send();
                }),

            Actions\Action::make('aiInvestigate')
                ->label('אבחון AI')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->visible($isAdmin)
                // Stay visible when the connection is off (so it doesn't look like
                // a missing feature) but disabled, with a hint to turn it on.
                ->disabled(fn (): bool => ! $this->record->mcp_enabled)
                ->tooltip(fn (): ?string => $this->record->mcp_enabled ? null : 'הפעילו קודם את חיבור ה-AI')
                ->form([
                    Textarea::make('goal')
                        ->label('מה לבדוק / לתקן?')
                        ->rows(2)
                        ->default('אבחן את האתר וזהה תקלות. אם נדרש תיקון — הצע פעולה אחת לאישור.'),
                ])
                ->action(function (array $data): void {
                    InvestigateSiteJob::dispatch($this->record->id, (string) ($data['goal'] ?? 'אבחן את האתר.'));
                    Notification::make()->title('האבחון רץ ברקע')
                        ->body('הסיכום יופיע בזיכרון האתר, והצעות תיקון (אם יהיו) ב"אישורי אוטומציה".')
                        ->success()->send();
                }),

            Actions\Action::make('testMcp')
                ->label('בדוק חיבור AI')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->visible(fn (): bool => ($this->record->mcp_enabled || filled($this->record->mcp_endpoint)) && $isAdmin())
                ->action(function (SiteConnector $connector): void {
                    $result = $connector->testConnection($this->record);

                    Notification::make()
                        ->title('חיבור סוכן AI — '.$this->record->domain)
                        ->body($result->message)
                        ->{$result->ok ? 'success' : 'warning'}()
                        ->send();
                }),

            Actions\ActionGroup::make([
                $this->proposeMcpAction(),
                $this->whitelistCloudflareAction(),
                $this->cloudflareRulesAction(),
                $this->purgeCloudflareCacheAction(),
                Actions\Action::make('connectionCodes')
                    ->label('קודי חיבור לתוסף')
                    ->icon('heroicon-o-clipboard-document')
                    ->visible($isAdmin)
                    ->modalHeading(fn (): string => 'קודי חיבור — '.$this->record->domain)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגור')
                    ->modalContent(fn () => view('filament.agent-credentials', [
                        'data' => $this->record->ensureAgentCredentials(),
                    ])),
                Actions\Action::make('downloadPlugin')
                    ->label('הורד תוסף (גרסה אחרונה)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible($isAdmin)
                    ->url(fn (): string => route('agent.plugin.latest'))
                    ->openUrlInNewTab(),
                $this->detectSiteTypeAction(),
                $this->generateAgentTokenAction(),
                Actions\EditAction::make()->label('עריכת הגדרות'),
            ])
                ->label('עוד כלים')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    /** Propose an MCP tool call (gated) — mirrors the table action for the page. */
    protected function proposeMcpAction(): Actions\Action
    {
        return Actions\Action::make('proposeMcp')
            ->label('פעולת AI')
            ->icon('heroicon-o-cpu-chip')
            ->color('warning')
            ->visible(fn (): bool => $this->record->mcp_enabled
                && filled(data_get($this->record->mcp_capabilities, 'tools'))
                && (auth()->user()?->isAdmin() ?? false))
            ->form(fn (): array => [
                Forms\Components\Select::make('tool')
                    ->label('כלי')
                    ->options(collect((array) data_get($this->record->mcp_capabilities, 'tools', []))
                        ->mapWithKeys(function (array $tool): array {
                            $name = (string) ($tool['name'] ?? '');

                            return [$name => "{$name} (".app(SiteToolCatalog::class)->resolveTierLabel($this->record, $name).')'];
                        })->all())
                    ->required()
                    ->searchable()
                    ->live(),
                Forms\Components\Group::make()
                    ->schema(fn (Forms\Get $get): array => SiteActions::toolParamFields($this->record, (string) $get('tool')))
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, ApprovalGate $gate): void {
                $catalog = app(SiteToolCatalog::class);
                $tool = (string) ($data['tool'] ?? '');

                if (! $catalog->allowedOn($this->record, $tool)) {
                    Notification::make()->title('הכלי מסווג כהרסני ומותר רק באתר סטייג׳ינג')->danger()->send();

                    return;
                }

                $arguments = SiteActions::collectToolArguments($this->record, $tool, $data);
                $argsText = $arguments === [] ? 'ללא פרמטרים' : json_encode($arguments, JSON_UNESCAPED_UNICODE);

                $gate->propose(
                    type: 'site_action',
                    summary: "🤖 פעולת AI באתר {$this->record->domain}\nכלי: {$tool} ({$catalog->resolveTierLabel($this->record, $tool)})\nפרמטרים: {$argsText}",
                    payload: ['site_id' => $this->record->id, 'tool' => $tool, 'arguments' => $arguments],
                    customerId: $this->record->customer_id,
                    proposedBy: 'team',
                );

                Notification::make()->title('הפעולה נשלחה לאישור')
                    ->body('תופיע ב"אישורי אוטומציה" ותישלח לוואטסאפ לאישור לפני ביצוע.')
                    ->success()->send();
            });
    }

    /** Whitelist our egress IP in the site's Cloudflare (page version). */
    protected function whitelistCloudflareAction(): Actions\Action
    {
        return Actions\Action::make('whitelistCloudflare')
            ->label('החרגת IP ב-Cloudflare')
            ->icon('heroicon-o-shield-check')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading(fn (): string => 'החרגת כתובת המערכת ב-Cloudflare — '.$this->record->domain)
            ->modalDescription('נחריג את כתובת ה-IP של המערכת מהגנות Cloudflare של האתר, כדי שחיבור הסוכן לא ייחסם. נדרש טוקן API של Cloudflare עם הרשאת עריכה ל-Firewall/IP Access Rules של הזון.')
            ->modalSubmitActionLabel('החרג עכשיו')
            ->form([
                Forms\Components\TextInput::make('api_token')
                    ->label('Cloudflare API Token')
                    ->password()->autocomplete('new-password')
                    ->required(fn (): bool => blank(config('billing.cloudflare.api_token')))
                    ->helperText(SiteActions::cloudflareTokenHint()),
            ])
            ->action(function (array $data): void {
                $ip = app(OutboundIp::class)->current();

                if ($ip === null) {
                    Notification::make()
                        ->title('לא זוהתה כתובת ה-IP של המערכת')
                        ->body('לא הצלחנו לזהות את כתובת ה-IP היוצאת של השרת. נסו שוב מאוחר יותר.')
                        ->danger()->send();

                    return;
                }

                $result = app(CloudflareClient::class)->whitelistIp(
                    SiteActions::cloudflareToken($data),
                    $this->record->domain,
                    $ip,
                    'Multi Digital agent — allow panel IP',
                );

                Notification::make()
                    ->title('Cloudflare — '.$this->record->domain)
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /**
     * Read-only viewer: list the site's existing Cloudflare IP Access Rules, so
     * the team can verify a whitelist/block from the panel instead of hunting in
     * the (frequently-reorganized) Cloudflare dashboard. Uses the saved token.
     */
    protected function cloudflareRulesAction(): Actions\Action
    {
        return Actions\Action::make('cloudflareRules')
            ->label('כללי IP ב-Cloudflare')
            ->icon('heroicon-o-list-bullet')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading(fn (): string => 'כללי IP ב-Cloudflare — '.$this->record->domain)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('סגור')
            // Fetch once when the modal opens; the page's 30s wire:poll must not
            // re-hit the Cloudflare API on every re-render, so the result is held
            // in component state and modalContent just reads it.
            ->mountUsing(function (): void {
                $token = trim((string) config('billing.cloudflare.api_token'));

                $this->cloudflareRulesResult = $token === ''
                    ? null
                    : app(CloudflareClient::class)->listAccessRules($token, $this->record->domain);
            })
            ->modalContent(function () {
                if ($this->cloudflareRulesResult === null) {
                    return new HtmlString(
                        '<div dir="rtl" class="text-sm">לא הוגדר טוקן Cloudflare. הגדירו אותו בהגדרות ← אינטגרציות כדי להציג את הכללים.</div>'
                    );
                }

                return view('filament.cloudflare-rules', ['result' => $this->cloudflareRulesResult]);
            });
    }

    /** Purge the site's Cloudflare cache (page version). */
    protected function purgeCloudflareCacheAction(): Actions\Action
    {
        return Actions\Action::make('purgeCloudflareCache')
            ->label('ניקוי קאש ב-Cloudflare')
            ->icon('heroicon-o-trash')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'ניקוי קאש ב-Cloudflare — '.$this->record->domain)
            ->modalDescription('ננקה את כל הקאש של האתר ב-Cloudflare. נדרש טוקן API עם הרשאת Cache Purge לזון.')
            ->modalSubmitActionLabel('נקה קאש')
            ->form([
                Forms\Components\TextInput::make('api_token')
                    ->label('Cloudflare API Token')
                    ->password()->autocomplete('new-password')
                    ->required(fn (): bool => blank(config('billing.cloudflare.api_token')))
                    ->helperText(SiteActions::cloudflareTokenHint()),
            ])
            ->action(function (array $data): void {
                $result = app(CloudflareClient::class)->purgeCache(SiteActions::cloudflareToken($data), $this->record->domain);

                Notification::make()
                    ->title('Cloudflare — '.$this->record->domain)
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /** Re-classify the site (store/brochure) from its installed plugins now. */
    protected function detectSiteTypeAction(): Actions\Action
    {
        return Actions\Action::make('detectSiteType')
            ->label('זהה סוג אתר (WooCommerce)')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->visible(fn (): bool => $this->record->mcp_enabled && (auth()->user()?->isAdmin() ?? false))
            ->action(function (): void {
                DetectSiteTypeJob::dispatch($this->record->id, force: true);

                Notification::make()
                    ->title('זיהוי סוג האתר רץ ברקע')
                    ->body('הסוג יתעדכן לפי התוספים המותקנים (WooCommerce = חנות).')
                    ->success()->send();
            });
    }

    /** Rotate the site's connection token (page version). */
    protected function generateAgentTokenAction(): Actions\Action
    {
        return Actions\Action::make('generateAgentToken')
            ->label('טוקן חדש')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->requiresConfirmation()
            ->modalHeading('החלפת טוקן חיבור לאתר')
            ->modalDescription('ייווצר טוקן חדש עבור התוסף באתר. הטוקן הקודם יבוטל — יש לעדכן את התוסף בטוקן החדש.')
            ->modalSubmitActionLabel('צור טוקן חדש')
            ->action(function (): void {
                $token = $this->record->generateAgentToken();

                Notification::make()
                    ->title('נוצר טוקן חדש — עדכנו אותו בתוסף')
                    ->body('הטוקן הקודם בוטל. הטוקן החדש (זמין גם ב"קודי חיבור לתוסף"):'."\n\n".$token)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /** Window (days) the uptime/response statistics are computed over. */
    protected const STATS_WINDOW_DAYS = 7;

    /** Recent probes shown in the history table. */
    protected const RECENT_LIMIT = 30;

    /**
     * Aggregate uptime %, average response time and probe count over the stats
     * window, computed in the database (no row hydration).
     *
     * @return array{total: int, up: int, uptime: ?float, avg_ms: ?int}
     */
    public function getStatsProperty(): array
    {
        $since = Carbon::now()->subDays(self::STATS_WINDOW_DAYS);

        $checks = $this->record->monitorChecks()
            ->where('checked_at', '>=', $since)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_up then 1 else 0 end) as up')
            ->selectRaw('avg(case when is_up then response_ms end) as avg_ms')
            ->first();

        $total = (int) ($checks->total ?? 0);
        $up = (int) ($checks->up ?? 0);

        return [
            'total' => $total,
            'up' => $up,
            'uptime' => $total > 0 ? round($up / $total * 100, 2) : null,
            'avg_ms' => $checks->avg_ms !== null ? (int) round($checks->avg_ms) : null,
        ];
    }

    /**
     * Most recent probes, newest first.
     *
     * @return Collection<int, MonitorCheck>
     */
    public function getRecentChecksProperty(): Collection
    {
        return $this->record->monitorChecks()
            ->latest('checked_at')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Response-time trend for the sparkline — the recent probes in
     * chronological order with a bar height (percent of the window's max).
     *
     * @return array{max: int, points: array<int, array{ms: int, up: bool, pct: int, at: Carbon}>}
     */
    public function getTrendProperty(): array
    {
        $checks = $this->record->monitorChecks()
            ->latest('checked_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['checked_at', 'response_ms', 'is_up'])
            ->reverse()
            ->values();

        $max = max(1, (int) $checks->max('response_ms'));

        return [
            'max' => $max,
            'points' => $checks->map(fn (MonitorCheck $c): array => [
                'ms' => (int) $c->response_ms,
                'up' => (bool) $c->is_up,
                'pct' => (int) round($c->response_ms / $max * 100),
                'at' => $c->checked_at,
            ])->all(),
        ];
    }

    public function getStatsWindowDays(): int
    {
        return self::STATS_WINDOW_DAYS;
    }
}
