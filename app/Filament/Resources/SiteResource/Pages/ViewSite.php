<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Filament\Support\SiteActions;
use App\Jobs\CheckDomainExpiryJob;
use App\Jobs\CheckSiteContentJob;
use App\Jobs\CheckSiteDnsJob;
use App\Jobs\CheckSiteLayoutJob;
use App\Jobs\CheckSiteReputationJob;
use App\Jobs\DetectSiteTypeJob;
use App\Jobs\InvestigateSiteJob;
use App\Jobs\RunSiteOperationJob;
use App\Jobs\ScanSiteComplianceJob;
use App\Jobs\ScanSiteVulnerabilitiesJob;
use App\Jobs\SendDomainRenewalReminderJob;
use App\Models\AuditLog;
use App\Models\MonitorCheck;
use App\Models\SystemLog;
use App\Services\Agent\SiteConnector;
use App\Services\Agent\SiteOperations;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Automation\ApprovalGate;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\Notifications\TemplateEngine;
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

            // Run a security scan now: match the site's installed plugins/themes/
            // core against the vulnerability feed. Results render in the "אבטחה"
            // section below; new findings also alert the team. Kept inline (next to
            // אבחון) — it's a day-to-day check, not a buried tool.
            Actions\Action::make('scanSecurity')
                ->label('סריקת אבטחה')
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning')
                ->visible($isAdmin)
                ->disabled(fn (): bool => ! $this->record->mcp_enabled)
                ->tooltip(fn (): ?string => $this->record->mcp_enabled ? null : 'הפעילו קודם את חיבור ה-AI')
                ->action(function (): void {
                    ScanSiteVulnerabilitiesJob::dispatch($this->record->id);
                    self::logManualCheck('סריקת אבטחה', $this->record->id);

                    Notification::make()->title('סריקת האבטחה רצה ברקע')
                        ->body('התוצאות יופיעו בעמוד האתר, וממצאים חדשים יישלחו גם לצוות. אם לא מופיעה תוצאה תוך כמה דקות — בדקו בניהול ← יומן אירועים.')
                        ->success()->send();
                }),

            // Check the domain against public spam/malware blocklists. Works even
            // without an AI connection — it queries external reputation sources.
            // Kept inline (next to אבחון) alongside the security scan.
            Actions\Action::make('checkReputation')
                ->label('בדיקת מוניטין')
                ->icon('heroicon-o-no-symbol')
                ->color('warning')
                ->visible($isAdmin)
                ->action(function (): void {
                    CheckSiteReputationJob::dispatch($this->record->id);
                    self::logManualCheck('בדיקת מוניטין', $this->record->id);

                    Notification::make()->title('בדיקת המוניטין רצה ברקע')
                        ->body('נבדוק את הדומיין מול מאגרי ספאם/נוזקות. התוצאה תופיע בעמוד האתר; אם לא — בדקו בניהול ← יומן אירועים.')
                        ->success()->send();
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
                // Only when the domain expires within the coming window (a month
                // by default — the same threshold the site card warns on), the
                // site has a customer, AND that customer has at least one reachable
                // channel (email or a WhatsApp JID/phone) — otherwise the reminder
                // is either premature or would silently reach no one.
                ->visible(fn (): bool => $this->record->domain_expiry_at !== null
                    && $this->record->domain_expiry_at->lte(now()->addDays((int) config('billing.monitoring.domain_warn_days', 30)))
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
                // Pick the channels — only the ones the customer actually has AND
                // whose template is enabled are offered (a disabled template would
                // silently send nothing); all offered ones are ticked by default.
                ->form(function (TemplateEngine $templates): array {
                    $available = array_filter([
                        'email' => filled($this->record->customer?->email)
                            && $templates->isEnabled('domain.renewal', 'email')
                            ? 'מייל ('.$this->record->customer->email.')' : null,
                        'whatsapp' => filled($this->record->customer?->whatsappRecipient())
                            && $templates->isEnabled('domain.renewal', 'whatsapp')
                            ? 'וואטסאפ' : null,
                    ]);

                    return [
                        Forms\Components\CheckboxList::make('channels')
                            ->label('ערוצי שליחה')
                            ->options($available)
                            ->default(array_keys($available))
                            ->required()
                            ->bulkToggleable(),
                    ];
                })
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

            // Shown ONLY under a standing defacement suspicion — the one-click
            // "this redesign is intentional" acceptance: re-baselines on the
            // current content and clears the alert.
            Actions\Action::make('acceptContent')
                ->label('אשר את התוכן הנוכחי')
                ->icon('heroicon-o-check-badge')
                ->color('danger')
                ->visible(fn (): bool => (bool) data_get($this->record->content_snapshot, 'suspected', false)
                    && (auth()->user()?->isAdmin() ?? false))
                ->requiresConfirmation()
                ->modalHeading('אישור התוכן הנוכחי כתקין')
                ->modalDescription('התוכן הנוכחי של דף הבית יאושר כבסיס החדש (למשל אחרי עיצוב מחודש מכוון), והחשד להשחתה יימחק. ודאו קודם שהאתר באמת תקין!')
                ->modalSubmitActionLabel('אשר — התוכן תקין')
                ->action(function (): void {
                    CheckSiteContentJob::dispatch($this->record->id, rebaseline: true);

                    Notification::make()->title('התוכן הנוכחי אושר')
                        ->body('הבסיס יתעדכן ברקע והחשד יימחק תוך רגע.')
                        ->success()->send();
                }),

            // Accessibility + legal-documents audit on demand. External (no AI
            // connection needed) — it reads the public homepage.
            Actions\Action::make('scanCompliance')
                ->label('סריקת נגישות ותאימות')
                ->icon('heroicon-o-scale')
                ->color('warning')
                ->visible($isAdmin)
                ->action(function (): void {
                    ScanSiteComplianceJob::dispatch($this->record->id);
                    self::logManualCheck('סריקת נגישות ותאימות', $this->record->id);

                    Notification::make()->title('הסריקה רצה ברקע')
                        ->body('נבדוק נגישות (ת"י 5568) ואת קיומם של מדיניות פרטיות, תנאי שימוש והצהרת נגישות. התוצאה תופיע בעמוד האתר.')
                        ->success()->send();
                }),

            // Shown ONLY while the layout looks broken: "the new design is
            // intentional" — re-baselines on the current structure.
            Actions\Action::make('acceptLayout')
                ->label('אשר את מבנה העמוד')
                ->icon('heroicon-o-check-badge')
                ->color('danger')
                ->visible(fn (): bool => data_get($this->record->layout_snapshot, 'status') === 'broken'
                    && (auth()->user()?->isAdmin() ?? false))
                ->requiresConfirmation()
                ->modalHeading('אישור מבנה העמוד הנוכחי')
                ->modalDescription('מבנה דף הבית הנוכחי יאושר כבסיס החדש (למשל אחרי עיצוב מחודש), וההתראה תיעלם. ודאו קודם שהעמוד באמת נראה כמו שצריך!')
                ->modalSubmitActionLabel('אשר — המבנה תקין')
                ->action(function (): void {
                    CheckSiteLayoutJob::dispatch($this->record->id, rebaseline: true);

                    Notification::make()->title('מבנה העמוד אושר')
                        ->body('הבסיס יתעדכן ברקע וההתראה תיעלם תוך רגע.')
                        ->success()->send();
                }),

            // Everything below sits in the "עוד כלים" dropdown. Only the few
            // most-used actions (diagnose, AI-connection toggle, and the
            // contextual domain-renewal reminder) stay inline, so the header row
            // of buttons stops overflowing the screen — mirrors the customer view.
            Actions\ActionGroup::make([
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

                // On-demand defacement check — the same fingerprint comparison
                // the daily watch runs.
                Actions\Action::make('checkContent')
                    ->label('בדיקת השחתה')
                    ->icon('heroicon-o-eye')
                    ->visible($isAdmin)
                    ->action(function (): void {
                        CheckSiteContentJob::dispatch($this->record->id);
                        self::logManualCheck('בדיקת השחתה', $this->record->id);

                        Notification::make()->title('בדיקת ההשחתה רצה ברקע')
                            ->body('תוכן דף הבית יושווה לבסיס המוכר; חשד ישלח התראה לצוות והתוצאה תופיע בעמוד האתר.')
                            ->success()->send();
                    }),

                // On-demand DNS snapshot/diff — the same check the daily watch
                // runs; useful right after a planned migration to re-baseline.
                Actions\Action::make('checkDns')
                    ->label('בדיקת DNS')
                    ->icon('heroicon-o-server-stack')
                    ->visible($isAdmin)
                    ->action(function (): void {
                        CheckSiteDnsJob::dispatch($this->record->id);
                        self::logManualCheck('בדיקת DNS', $this->record->id);

                        Notification::make()->title('בדיקת ה-DNS רצה ברקע')
                            ->body('רשומות ה-A/MX/NS יושוו לתמונה הקודמת; שינוי ישלח התראה לצוות והתוצאה תופיע בעמוד האתר.')
                            ->success()->send();
                    }),

                // Re-read the registration date from the registry, right now.
                //
                // The daily check leaves the cached date alone when the registry
                // doesn't answer, which is right — but it means a customer who
                // renewed can keep seeing "פג" for as long as the lookup keeps
                // failing, with no way to ask again. This asks again, and says
                // plainly which of the two happened.
                Actions\Action::make('checkDomain')
                    ->label('בדיקת תוקף דומיין')
                    ->icon('heroicon-o-calendar-days')
                    // הבדיקה עצמה מדלגת על אתר שהניטור שלו כבוי, ולכן היא הייתה
                    // מדווחת "הרישום לא ענה" בלי ששאלה אותו דבר. נשארת על המסך
                    // (כדי שלא תיראה כיכולת חסרה) ומסבירה למה היא כבויה.
                    ->disabled(fn (): bool => ! $this->record->monitor_enabled)
                    ->tooltip(fn (): ?string => $this->record->monitor_enabled
                        ? null
                        : 'הניטור של האתר כבוי — הפעילו אותו בהגדרות האתר כדי לבדוק את תוקף הדומיין')
                    ->action(function (): void {
                        $before = $this->record->domain_checked_at;

                        CheckDomainExpiryJob::dispatchSync($this->record->id);
                        self::logManualCheck('בדיקת תוקף דומיין', $this->record->id);

                        $site = $this->record->refresh();
                        $answered = $site->domain_checked_at !== null
                            && ($before === null || $site->domain_checked_at->gt($before));

                        if (! $answered) {
                            Notification::make()->title('רשם הדומיינים לא ענה')
                                ->body('לא התקבלה תשובה מהרישום, והתאריך שמוצג הוא הקריאה הקודמת ולא המצב הנוכחי. אפשר לנסות שוב בעוד כמה דקות.')
                                ->warning()->send();

                            return;
                        }

                        Notification::make()->title('תוקף הדומיין עודכן')
                            ->body('לפי הרישום, '.$site->domain.' בתוקף עד '.$site->domain_expiry_at?->format('d/m/Y').'.')
                            ->success()->send();
                    }),

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
                // Recovery for an MCP key that was corrupted (e.g. by browser
                // autofill overwriting it with the manager's panel password):
                // mint a fresh random key, then copy it into the plugin.
                Actions\Action::make('rotateMcpSecret')
                    ->label('מפתח MCP חדש')
                    ->icon('heroicon-o-key')
                    ->visible($isAdmin)
                    ->requiresConfirmation()
                    ->modalHeading('החלפת מפתח ה-MCP')
                    ->modalDescription('ייווצר מפתח אקראי חדש. חובה להעתיק אותו גם לתוסף באתר ("קודי חיבור לתוסף") — עד אז חיבור ה-AI לאתר לא יעבוד.')
                    ->action(function (): void {
                        $this->record->update(['mcp_secret' => Str::random(40)]);

                        Notification::make()->title('נוצר מפתח MCP חדש')
                            ->body('פתחו "קודי חיבור לתוסף", העתיקו את המפתח החדש והדביקו אותו בתוסף באתר.')
                            ->success()->send();
                    }),
                ...$this->siteOperationActions(),
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
    /**
     * Record a manually-requested check in the event log the moment it is
     * queued. A completed run shows up as a fresh timestamp on the check's
     * card in the site page (success) or as a log entry here (skip/failure/
     * crash via failed()); when NEITHER appears, the queue worker isn't
     * processing — the one failure mode the job itself can never report.
     */
    private static function logManualCheck(string $label, int $siteId): void
    {
        SystemLog::record('info', 'monitoring',
            "{$label} נשלחה ידנית לתור עבור אתר #{$siteId} — ממתינה לעיבוד. בסיום, התוצאה תופיע בקוביית הבדיקה בעמוד האתר (תאריך הבדיקה יתעדכן); דילוג או כשל יירשמו כאן. אם תוך דקות אין לא זה ולא זה — ודאו שמעבד התור (Horizon) רץ בשרת.",
            ['site_id' => $siteId]);
    }

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
            ->modalDescription('נחריג את כתובת ה-IP של המערכת מהגנות Cloudflare של האתר, כדי שחיבור הסוכן לא ייחסם. משתמש בטוקן ה-Cloudflare השמור בהגדרות ← אינטגרציות.')
            ->modalSubmitActionLabel('החרג עכשיו')
            ->form([
                SiteActions::cloudflareTokenField(),
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
            ->modalDescription('ננקה את כל הקאש של האתר ב-Cloudflare. משתמש בטוקן השמור בהגדרות ← אינטגרציות.')
            ->modalSubmitActionLabel('נקה קאש')
            ->form([
                SiteActions::cloudflareTokenField(),
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

    /**
     * The operations a manager runs on the site itself (page version).
     *
     * One button per entry in `SiteOperations`, so adding the next operation the
     * team wants to run from the panel is one entry there — not a new button,
     * a new job and a new screen.
     *
     * @return array<int, Actions\Action>
     */
    protected function siteOperationActions(): array
    {
        return collect(SiteOperations::all())
            ->map(fn (array $operation, string $key): Actions\Action => Actions\Action::make('siteOperation_'.$key)
                ->label($operation['label'])
                ->icon($operation['icon'])
                ->color($operation['color'])
                ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                // Disabled rather than hidden, with the reason: a button that
                // disappears reads as a feature the panel does not have.
                ->disabled(fn (): bool => SiteActions::operationBlockedBy($this->record, $key) !== null)
                ->tooltip(fn (): ?string => SiteActions::operationBlockedBy($this->record, $key))
                ->requiresConfirmation()
                ->modalHeading($operation['heading'].' — '.$this->record->domain)
                // What it does and what it costs, both before the click.
                ->modalDescription($operation['what']."\n\n".$operation['cost'])
                ->modalSubmitActionLabel($operation['submit'])
                ->action(function () use ($key, $operation): void {
                    RunSiteOperationJob::dispatch($this->record->id, $key, auth()->id());

                    AuditLog::record('updated', $operation['label'].' — '.$this->record->domain);

                    Notification::make()
                        ->title($operation['label'].' — יצאה לביצוע')
                        ->body('הפעולה רצה ברקע מול האתר. התוצאה — הצלחה או כישלון — תגיע בפעמון ההתראות ותירשם ביומן השינויים של האתר.')
                        ->success()->send();
                }))
            ->values()
            ->all();
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
