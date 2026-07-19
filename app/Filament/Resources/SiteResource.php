<?php

namespace App\Filament\Resources;

use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Support\SiteActions;
use App\Jobs\RestoreSiteJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Site;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\SiteDiagnostics;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Js;
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
                            // modal all agree on one value. Also self-heal a
                            // malformed value (e.g. a doubled scheme).
                            ->afterStateHydrated(function (Forms\Set $set, ?string $state, ?Site $record): void {
                                $malformed = filled($state) && substr_count((string) $state, '://') > 1;
                                if (($malformed || blank($state)) && $record !== null && filled($record->domain)) {
                                    $set('mcp_endpoint', $record->conventionalMcpEndpoint());
                                }
                            })
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('copyMcpEndpoint')
                                    ->icon('heroicon-m-clipboard-document')
                                    ->tooltip('העתק')
                                    ->action(function (?string $state, $livewire): void {
                                        if (blank($state)) {
                                            return;
                                        }
                                        $livewire->js('window.navigator.clipboard.writeText('.Js::from($state).')');
                                        Notification::make()->title('כתובת MCP הועתקה')->success()->send();
                                    }),
                            )
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
                                // The codes (a per-site token + secret) are generated
                                // against the saved row, so they only exist once the
                                // site is created. On the new-site screen the button
                                // stays visible but disabled, with a tooltip that says
                                // why — rather than vanishing and looking like a bug.
                                ->disabled(fn (?Site $record): bool => $record === null)
                                ->tooltip(fn (?Site $record): ?string => $record === null
                                    ? 'שמרו את האתר תחילה — הקודים נוצרים לכל אתר בנפרד.'
                                    : null)
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
            // Each site is a card, not a row — the whole card links to the site
            // page (full monitoring + options), and the day-to-day actions sit
            // right on the card as shortcuts, so common jobs need no drill-in.
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('domain')
                        ->label('דומיין')
                        ->weight('bold')
                        ->size('lg')
                        ->icon('heroicon-m-globe-alt')
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('customer.name')
                        ->label('לקוח')
                        ->color('gray')
                        ->icon('heroicon-m-user')
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('status')
                            ->badge()
                            ->color(fn (SiteStatus $state): string => match ($state) {
                                SiteStatus::Active => 'success',
                                SiteStatus::Suspended => 'danger',
                            })
                            ->grow(false),
                        Tables\Columns\TextColumn::make('ssl_days_left')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state === null ? 'SSL —' : "SSL · {$state} ימים")
                            ->color(fn ($state): string => $state === null ? 'gray' : ($state <= 0 ? 'danger' : ($state <= (int) config('billing.monitoring.ssl_warn_days', 14) ? 'warning' : 'success')))
                            ->grow(false),
                        Tables\Columns\TextColumn::make('monitor_enabled')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state ? 'ניטור פעיל' : 'ללא ניטור')
                            ->icon(fn ($state): string => $state ? 'heroicon-m-signal' : 'heroicon-m-signal-slash')
                            ->color(fn ($state): string => $state ? 'success' : 'gray')
                            ->grow(false),
                    ]),
                ])->space(3),
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
                // The two most-used shortcuts sit directly on the card.
                self::diagnoseAction()->iconButton(),
                SiteActions::aiInvestigate()->iconButton(),

                // Everything else in one tidy "more" menu, so the card stays clean.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('מידע וניטור')->icon('heroicon-o-chart-bar'),
                    self::proposeFixAction(),
                    self::suspendAction(),
                    self::restoreAction(),
                    SiteActions::proposeMcpAction(),
                    SiteActions::testMcp(),
                    SiteActions::connectionCodes(),
                    SiteActions::whitelistCloudflare(),
                    SiteActions::purgeCloudflareCache(),
                    SiteActions::downloadPlugin(),
                    SiteActions::generateAgentToken(),
                    Tables\Actions\EditAction::make()->label('עריכה'),
                ])
                    ->label('עוד פעולות')
                    ->icon('heroicon-m-ellipsis-horizontal')
                    ->button()
                    ->color('gray'),
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
     * Read-only WordPress diagnostics: live probe + SSL + uptime, with a
     * suggested fix the owner can send to the approval gate. Shared between the
     * card grid and the site page so both offer the exact same action.
     */
    public static function diagnoseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('diagnose')
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
            });
    }

    /** Propose a reversible hosting fix (owner approves via WhatsApp/panel). */
    public static function proposeFixAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('proposeFix')
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
            });
    }

    /** Suspend the site at the hosting provider (maintenance mode). */
    public static function suspendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('suspend')
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
            });
    }

    /** Restore a suspended site to full activity. */
    public static function restoreAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore')
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
            });
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
