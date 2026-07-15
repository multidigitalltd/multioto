<?php

namespace App\Filament\Resources;

use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource\Pages;
use App\Jobs\InvestigateSiteJob;
use App\Jobs\RestoreSiteJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Site;
use App\Services\Agent\SiteConnector;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\SiteDiagnostics;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'אתרים';

    protected static ?string $modelLabel = 'אתר';

    protected static ?string $pluralModelLabel = 'אתרים';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'domain';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('האתר')
                    ->description('לקוח ודומיין')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('לקוח')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('domain')
                            ->label('דומיין')
                            ->required()
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('ניטור')
                    ->schema([
                        Forms\Components\TextInput::make('monitor_url')
                            ->label('כתובת לניטור')
                            ->url()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('monitor_enabled')
                            ->label('ניטור פעיל')
                            ->inline(false)
                            ->required(),
                        Forms\Components\TextInput::make('expected_keyword')
                            ->label('מילת מפתח לבדיקת תוכן (אופציונלי)')
                            ->helperText('אם מוגדר — האתר ייחשב תקין רק אם הטקסט הזה מופיע בעמוד. מזהה "עמוד לבן"/פריצה גם כשה-HTTP תקין.')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('אחסון וסטטוס')
                    ->schema([
                        Forms\Components\TextInput::make('hosting_ref')
                            ->label('מזהה אחסון')
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(SiteStatus::class)
                            ->required(),
                    ])->columns(2),

                // Connection to the AI site agent (MCP). Admin-only: it holds the
                // endpoint and the per-site secret we present to the site.
                Forms\Components\Section::make('חיבור סוכן AI')
                    ->description('חיבור MCP לאתר — הפעולות עצמן תמיד עוברות אישור מנהל.')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->schema([
                        Forms\Components\Toggle::make('mcp_enabled')
                            ->label('חיבור פעיל')
                            ->inline(false),
                        Forms\Components\Select::make('environment')
                            ->label('סביבה')
                            ->options(['production' => 'ייצור', 'staging' => 'סטייג׳ינג'])
                            ->default('production')
                            ->native(false),
                        Forms\Components\TextInput::make('mcp_endpoint')
                            ->label('כתובת MCP')
                            ->url()
                            ->maxLength(255)
                            // Pre-fill the conventional endpoint for an existing
                            // site so the field, the DB and the "connection codes"
                            // modal all agree on one value.
                            ->afterStateHydrated(function (Forms\Set $set, ?string $state, ?Site $record): void {
                                if (blank($state) && $record !== null && filled($record->domain)) {
                                    $set('mcp_endpoint', $record->conventionalMcpEndpoint());
                                }
                            })
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('mcp_secret')
                            ->label('מפתח MCP')
                            ->password()
                            ->maxLength(255)
                            ->helperText('נוצר אוטומטית ב"קודי חיבור לתוסף". בעריכה — השאירו ריק כדי לא לשנות.')
                            // Never render the stored secret back into the form;
                            // a blank field means "leave unchanged".
                            ->formatStateUsing(fn (): ?string => null)
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->columnSpanFull(),

                        // Where a manager sets up the connection: grab the plugin
                        // and copy the ready-made codes into the site — no need to
                        // invent a secret or a token.
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('downloadPlugin')
                                ->label('הורד תוסף (גרסה אחרונה)')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->url(fn (): string => route('agent.plugin.latest'))
                                ->openUrlInNewTab(),
                            Forms\Components\Actions\Action::make('connectionCodes')
                                ->label('קודי חיבור לתוסף')
                                ->icon('heroicon-o-clipboard-document')
                                ->color('primary')
                                // Needs a saved site to generate/store codes against.
                                ->visible(fn (?Site $record): bool => $record !== null)
                                ->modalHeading(fn (?Site $record): string => 'קודי חיבור — '.($record?->domain ?? ''))
                                ->modalSubmitAction(false)
                                ->modalCancelActionLabel('סגור')
                                ->modalContent(fn (Site $record) => view('filament.agent-credentials', [
                                    'data' => $record->ensureAgentCredentials(),
                                ])),
                        ])->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('domain')
                    ->label('דומיין')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('monitor_url')
                    ->label('כתובת לניטור')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('monitor_enabled')
                    ->label('ניטור פעיל')
                    ->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (SiteStatus $state): string => match ($state) {
                        SiteStatus::Active => 'success',
                        SiteStatus::Suspended => 'danger',
                    }),
                Tables\Columns\TextColumn::make('ssl_days_left')
                    ->label('SSL (ימים)')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn ($state): string => $state === null ? 'gray' : ($state <= 0 ? 'danger' : ($state <= (int) config('billing.monitoring.ssl_warn_days', 14) ? 'warning' : 'success'))),
                Tables\Columns\TextColumn::make('hosting_ref')
                    ->label('מזהה אחסון')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('domain', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(SiteStatus::class),
                Tables\Filters\TernaryFilter::make('monitor_enabled')
                    ->label('ניטור פעיל'),
            ])
            ->actions([
                // Read-only WordPress diagnostics: live probe + SSL + uptime,
                // with a suggested fix the owner can send to the approval gate.
                Tables\Actions\Action::make('diagnose')
                    ->label('אבחון')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->action(function (Site $record, SiteDiagnostics $diagnostics): void {
                        try {
                            $result = $diagnostics->run($record);
                        } catch (\Throwable $e) {
                            Notification::make()->title('האבחון נכשל')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('אבחון '.$record->domain.($result['healthy'] ? ' — תקין ✓' : ' — נמצאו בעיות'))
                            ->body($result['summary']) // includes the suggested fix in Hebrew
                            ->{$result['healthy'] ? 'success' : 'warning'}()
                            ->persistent()
                            ->send();
                    }),

                // Propose a reversible fix (owner approves via WhatsApp/panel).
                Tables\Actions\Action::make('proposeFix')
                    ->label('הצע תיקון')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->visible(fn (Site $record): bool => self::hostingActionable($record))
                    ->form([
                        Forms\Components\Select::make('fix')
                            ->label('התיקון המוצע')
                            ->options([
                                'clear_cache' => 'ניקוי מטמון (Cache)',
                                'restart' => 'הפעלה מחדש של האתר',
                                'maintenance_on' => 'הכנסה למצב תחזוקה',
                                'maintenance_off' => 'הוצאה ממצב תחזוקה',
                            ])
                            ->default('clear_cache')
                            ->required(),
                    ])
                    ->action(function (array $data, Site $record, ApprovalGate $gate): void {
                        self::proposeSiteFix($gate, $record, $data['fix']);
                        Notification::make()->title('התיקון נשלח לאישור')
                            ->body('הבקשה תופיע ב"אישורי אוטומציה" ותישלח לוואטסאפ שלך לאישור לפני ביצוע.')
                            ->success()->send();
                    }),

                Tables\Actions\Action::make('suspend')
                    ->label('השהה')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->visible(fn (Site $record): bool => $record->status === SiteStatus::Active
                        && self::hostingActionable($record))
                    ->requiresConfirmation()
                    ->modalHeading('השהיית אתר')
                    ->modalDescription(fn (Site $record): string => "להשהות את {$record->domain}? האתר יעבור למצב תחזוקה אצל ספק האחסון.")
                    ->modalSubmitActionLabel('השהה')
                    ->action(function (Site $record): void {
                        SuspendSiteJob::dispatch($record->id);

                        Notification::make()
                            ->title('ההשהיה נשלחה לביצוע')
                            ->body('הסטטוס יתעדכן תוך רגעים.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('restore')
                    ->label('שחזר')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Site $record): bool => $record->status === SiteStatus::Suspended
                        && self::hostingActionable($record))
                    ->requiresConfirmation()
                    ->modalHeading('שחזור אתר')
                    ->modalDescription(fn (Site $record): string => "לשחזר את {$record->domain} לפעילות מלאה?")
                    ->modalSubmitActionLabel('שחזר')
                    ->action(function (Site $record): void {
                        RestoreSiteJob::dispatch($record->id);

                        Notification::make()
                            ->title('השחזור נשלח לביצוע')
                            ->success()
                            ->send();
                    }),

                // Let the AI investigate the site (read-only) and file any fix as
                // an approval proposal. Runs in the background; admin-only.
                Tables\Actions\Action::make('aiInvestigate')
                    ->label('אבחון AI')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->visible(fn (Site $record): bool => $record->mcp_enabled
                        && app(ClaudeClient::class)->isEnabled()
                        && (auth()->user()?->isAdmin() ?? false))
                    ->form([
                        Forms\Components\Textarea::make('goal')
                            ->label('מה לבדוק?')
                            ->rows(2)
                            ->default('אבחן את האתר וזהה תקלות. אם נדרש תיקון — הצע פעולה אחת לאישור.'),
                    ])
                    ->action(function (array $data, Site $record): void {
                        InvestigateSiteJob::dispatch($record->id, (string) ($data['goal'] ?? 'אבחן את האתר.'));

                        Notification::make()->title('האבחון רץ ברקע')
                            ->body('הסיכום יופיע בזיכרון האתר, והצעות תיקון (אם יהיו) ב"אישורי אוטומציה" לאישורך.')
                            ->success()->send();
                    }),

                // Propose an MCP tool call on this site — goes through the same
                // approval gate as every automated action (manager approves on
                // WhatsApp or in the panel before anything runs). Admin-only.
                Tables\Actions\Action::make('proposeMcpAction')
                    ->label('פעולת AI')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('warning')
                    ->visible(fn (Site $record): bool => $record->mcp_enabled
                        && filled(data_get($record->mcp_capabilities, 'tools'))
                        && (auth()->user()?->isAdmin() ?? false))
                    ->form(fn (Site $record): array => [
                        Forms\Components\Select::make('tool')
                            ->label('כלי')
                            ->options(collect((array) data_get($record->mcp_capabilities, 'tools', []))
                                ->mapWithKeys(function (array $tool) use ($record): array {
                                    $name = (string) ($tool['name'] ?? '');
                                    $tier = app(SiteToolCatalog::class)->resolveTierLabel($record, $name);

                                    return [$name => "{$name} ({$tier})"];
                                })->all())
                            ->required()
                            ->searchable(),
                        Forms\Components\Textarea::make('arguments')
                            ->label('פרמטרים (JSON)')
                            ->rows(3)
                            ->placeholder('{"plugin": "elementor"}')
                            ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                if (filled($value) && ! is_array(json_decode((string) $value, true))) {
                                    $fail('הפרמטרים חייבים להיות JSON תקין.');
                                }
                            }),
                    ])
                    ->action(function (array $data, Site $record, ApprovalGate $gate): void {
                        $catalog = app(SiteToolCatalog::class);
                        $tool = (string) $data['tool'];

                        if (! $catalog->allowedOn($record, $tool)) {
                            Notification::make()->title('הכלי מסווג כהרסני ומותר רק באתר סטייג׳ינג')->danger()->send();

                            return;
                        }

                        $arguments = filled($data['arguments'] ?? null) ? (array) json_decode((string) $data['arguments'], true) : [];
                        $argsText = $arguments === [] ? 'ללא פרמטרים' : json_encode($arguments, JSON_UNESCAPED_UNICODE);

                        $gate->propose(
                            type: 'site_action',
                            summary: "🤖 פעולת AI באתר {$record->domain}\nכלי: {$tool} ({$catalog->resolveTierLabel($record, $tool)})\nפרמטרים: {$argsText}",
                            payload: ['site_id' => $record->id, 'tool' => $tool, 'arguments' => $arguments],
                            customerId: $record->customer_id,
                            proposedBy: 'team',
                        );

                        Notification::make()->title('הפעולה נשלחה לאישור')
                            ->body('תופיע ב"אישורי אוטומציה" ותישלח לוואטסאפ לאישור לפני ביצוע.')
                            ->success()->send();
                    }),

                // Live MCP handshake: verify the site's agent connection and
                // refresh its cached tool list. Admin-only, read-only.
                Tables\Actions\Action::make('testMcp')
                    ->label('בדוק חיבור AI')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->visible(fn (Site $record): bool => ($record->mcp_enabled || filled($record->mcp_endpoint))
                        && (auth()->user()?->isAdmin() ?? false))
                    ->action(function (Site $record, SiteConnector $connector): void {
                        $result = $connector->testConnection($record);

                        Notification::make()
                            ->title('חיבור סוכן AI — '.$record->domain)
                            ->body($result->message)
                            ->{$result->ok ? 'success' : 'warning'}()
                            ->send();
                    }),

                // Issue a fresh per-site connection token for the companion
                // plugin. Shown once — installed into the site's plugin config —
                // and rotating it revokes the previous one. Admin-only.
                // The ready-made connection codes (endpoint, secret, token) to
                // copy straight into the site's plugin — generated once, then
                // re-displayable any time. Admin-only.
                Tables\Actions\Action::make('connectionCodes')
                    ->label('קודי חיבור')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('primary')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->modalHeading(fn (Site $record): string => 'קודי חיבור — '.$record->domain)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגור')
                    ->modalContent(fn (Site $record) => view('filament.agent-credentials', [
                        'data' => $record->ensureAgentCredentials(),
                    ])),

                // Download the current plugin build to install on a site. Admin-only.
                Tables\Actions\Action::make('downloadPlugin')
                    ->label('הורד תוסף')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->url(fn (): string => route('agent.plugin.latest'))
                    ->openUrlInNewTab(),

                // Rotate the site's connection token — revokes the previous one.
                Tables\Actions\Action::make('generateAgentToken')
                    ->label('טוקן חדש')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('החלפת טוקן חיבור לאתר')
                    ->modalDescription('ייווצר טוקן חדש עבור התוסף באתר. הטוקן הקודם יבוטל — יש לעדכן את התוסף בטוקן החדש.')
                    ->modalSubmitActionLabel('צור טוקן חדש')
                    ->action(function (Site $record): void {
                        $token = $record->generateAgentToken();

                        Notification::make()
                            ->title('נוצר טוקן חדש — עדכנו אותו בתוסף')
                            ->body('הטוקן הקודם בוטל. הטוקן החדש (זמין גם ב"קודי חיבור"):'."\n\n".$token)
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make()->label('ניטור'),

                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין אתרים עדיין')
            ->emptyStateDescription('הקימו אתר חדש דרך "אתר חדש" בתפריט.');
    }

    /**
     * Whether the suspend/restore quick actions can actually take effect. The
     * real FlyWP driver needs a linked site (hosting_ref); the 'log' driver just
     * records intent, so it's always actionable. Prevents enqueuing a job that
     * would fail while the UI reports success.
     */
    protected static function hostingActionable(Site $record): bool
    {
        if (config('billing.hosting.driver') === 'flywp') {
            return filled($record->hosting_ref);
        }

        return true;
    }

    /** Record a site-fix proposal on the approval gate (owner approves to run). */
    protected static function proposeSiteFix(ApprovalGate $gate, Site $site, string $fix): void
    {
        $gate->propose(
            type: 'site_fix',
            summary: sprintf(
                "תיקון אתר %s (%s): %s.\nיבוצע רק לאחר אישורך, וניתן לשחזור.",
                $site->domain,
                $site->customer?->name ?? 'לקוח',
                SiteDiagnostics::FIX_LABELS[$fix] ?? $fix,
            ),
            payload: ['site_id' => $site->id, 'fix' => $fix],
            customerId: $site->customer_id,
        );
    }

    public static function getRelations(): array
    {
        return [
            SiteResource\RelationManagers\MemoriesRelationManager::class,
            SiteResource\RelationManagers\ChangesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'view' => Pages\ViewSite::route('/{record}'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
