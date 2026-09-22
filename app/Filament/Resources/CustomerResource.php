<?php

namespace App\Filament\Resources;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Customers\OnboardingChecklist;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Support\CardLink;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerResource extends Resource
{
    use RespectsModuleAccess;

    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'לקוחות';

    protected static ?string $modelLabel = 'לקוח';

    protected static ?string $pluralModelLabel = 'לקוחות';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('פרטי הלקוח')
                    ->description('שם ופרטי העסק')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('שם הלקוח / העסק')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('business_number')
                            ->label('ח.פ / עוסק')
                            ->maxLength(20),
                        Forms\Components\Select::make('business_type')
                            ->label('סוג עסק')
                            ->options(BusinessType::class)
                            ->required(),
                        Forms\Components\Toggle::make('vat_exempt')
                            ->label('פטור ממע״מ')
                            ->helperText('חשבוניות יונפקו ללא מע״מ')
                            ->inline(false),
                    ])->columns(2),

                Forms\Components\Section::make('פרטי התקשרות')
                    ->schema([
                        Forms\Components\TextInput::make('contact_name')
                            ->label('איש קשר')
                            ->maxLength(120),
                        Forms\Components\TextInput::make('phone')
                            ->label('טלפון')
                            ->tel(),
                        Forms\Components\TextInput::make('email')
                            ->label('אימייל')
                            ->email(),
                        Forms\Components\TextInput::make('address')
                            ->label('כתובת')
                            ->maxLength(190),
                        Forms\Components\TextInput::make('whatsapp_jid')
                            ->label('מזהה וואטסאפ')
                            ->helperText('אופציונלי — מזוהה אוטומטית מהודעות נכנסות')
                            ->maxLength(255),
                        Forms\Components\Select::make('payment_method')
                            ->label('אמצעי תשלום מועדף')
                            ->options([
                                'credit_card' => 'כרטיס אשראי',
                                'standing_order' => 'הוראת קבע בנקאית',
                                'bank_transfer' => 'העברה בנקאית',
                                'checks' => 'צ׳קים',
                            ])
                            ->placeholder('—'),
                    ])->columns(2),

                Forms\Components\Section::make('סטטוס והערות')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(CustomerStatus::class)
                            ->default(CustomerStatus::Active)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('הערות פנימיות')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('שם הלקוח')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('טלפון')
                    ->searchable()
                    ->icon('heroicon-m-phone'),
                Tables\Columns\TextColumn::make('email')
                    ->label('אימייל')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('business_type')
                    ->label('סוג עסק')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('vat_exempt')
                    ->label('פטור ממע״מ')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (CustomerStatus $state): string => match ($state) {
                        CustomerStatus::Active => 'success',
                        CustomerStatus::Suspended => 'warning',
                        CustomerStatus::Churned => 'gray',
                    }),
                // The security card, answered on screen rather than left to be
                // discovered the day a collection fails. The customer record is
                // saved before the card page opens, so "signed up" and "gave us
                // a card" are two different facts and only one of them was
                // visible here.
                Tables\Columns\IconColumn::make('has_security_card')
                    ->label('כרטיס ביטחון')
                    ->state(fn (Customer $record): bool => (bool) $record->has_active_card)
                    ->boolean()
                    ->trueIcon('heroicon-o-credit-card')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->falseColor('danger')
                    ->tooltip(fn (Customer $record): string => $record->has_active_card
                        ? 'כרטיס שמור וניתן לחיוב'
                        : ($record->security_card_terms_at !== null
                            ? 'הלקוח אישר להשאיר כרטיס ביטחון ולא הזין אותו — אין ממה לגבות אם תשלום לא יגיע'
                            : 'אין כרטיס שמור (הלקוח נרשם לפני שנדרש כרטיס ביטחון)'))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('marketing_opt_out_at')
                    ->label('הוסר מדיוור')
                    ->boolean()
                    ->trueIcon('heroicon-o-no-symbol')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-envelope')
                    ->falseColor('gray')
                    ->tooltip(fn (Customer $record): string => $record->hasOptedOutOfMarketing()
                        ? 'ביקש להסיר אותו מדיוור פרסומי ב-'.$record->marketing_opt_out_at->format('d/m/Y')
                        : 'מקבל דיוור פרסומי')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(CustomerStatus::class),
                Tables\Filters\TernaryFilter::make('vat_exempt')
                    ->label('פטור ממע״מ'),
                // The work list this whole mechanism exists to produce: who
                // agreed to leave a card and never did.
                Tables\Filters\Filter::make('missing_security_card')
                    ->label('חסר כרטיס ביטחון')
                    ->query(fn (Builder $query): Builder => $query->missingSecurityCard()),
            ])
            ->actions([
                Tables\Actions\Action::make('sendCardLink')
                    ->label('קישור לכרטיס')
                    ->icon('heroicon-o-credit-card')
                    // Not gated on having a subscription. A customer who left
                    // before the card page has no subscription yet, and they
                    // are precisely the one the team needs to be able to ask.
                    ->visible(fn (Customer $record): bool => filled($record->phone ?? $record->email))
                    ->requiresConfirmation()
                    ->modalHeading('שליחת קישור להזנת כרטיס')
                    ->modalDescription(fn (Customer $record): string => "לשלוח ל-{$record->name} קישור מאובטח להזנת/עדכון כרטיס אשראי (וואטסאפ + מייל)?")
                    ->modalSubmitActionLabel('שלח')
                    ->action(function (Customer $record, CardCaptureLinkSender $sender): void {
                        $subscription = $record->subscriptions()
                            ->whereNot('status', SubscriptionStatus::Canceled)
                            ->with(['customer', 'plan'])
                            ->orderBy('id')
                            ->first();

                        // No subscription — either it was canceled between
                        // render and click, or this customer never had one. The
                        // card link is customer-wide either way; what changes is
                        // the wording, since a message about activating a
                        // subscription would name one that does not exist.
                        if ($subscription === null) {
                            self::notifyLinkResult($sender->sendSignupCardRequest($record));

                            return;
                        }

                        self::notifyLinkResult($sender->send($subscription));
                    }),

                // Show the signed card-capture link on screen to copy/open
                // directly — works even before WhatsApp/email are connected, and
                // is the quickest way to capture a card for a manual charge test.
                Tables\Actions\Action::make('copyCardLink')
                    ->label('העתקת קישור לכרטיס')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->modalHeading('קישור מאובטח להזנת כרטיס')
                    ->modalDescription('העתיקו את הקישור ושִלחו ללקוח, או פִּתחו אותו בעצמכם כדי להזין כרטיס (הכרטיס מוזן בעמוד המאובטח של קארדקום). הקישור חתום ופג תוקף.')
                    ->modalContent(fn (Customer $record) => view('forms.copyable-link', ['link' => CardLink::for($record->id)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגור'),

                Tables\Actions\ViewAction::make()->label('כרטיס לקוח'),
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין לקוחות עדיין')
            ->emptyStateDescription('הקימו לקוח חדש דרך "לקוח חדש" בתפריט.');
    }

    /**
     * Turn a CardCaptureLinkSender result into an honest notification — which
     * channel actually delivered, and why any failed.
     *
     * @param  array{link: string, sent: array<int, string>, failed: array<int, string>}  $result
     */
    public static function notifyLinkResult(array $result): void
    {
        $skipped = $result['skipped'] ?? [];

        if ($result['sent'] !== [] && $result['failed'] === []) {
            Notification::make()->title('הקישור נשלח: '.implode(', ', $result['sent']).' ✓')->success()->send();
        } elseif ($result['sent'] !== []) {
            Notification::make()
                ->title('נשלח חלקית')
                ->body('נשלח: '.implode(', ', $result['sent']).'. נכשל: '.implode('; ', $result['failed']))
                ->warning()->persistent()->send();
        } elseif ($result['failed'] !== []) {
            Notification::make()
                ->title('השליחה נכשלה')
                ->body(implode('; ', $result['failed']).' — אפשר להעתיק את הקישור ידנית בכפתור "העתקת קישור לכרטיס".')
                ->danger()->persistent()->send();
        } else {
            // Nothing failed — delivery was intentionally skipped (message off /
            // no contact). Not an error: the link was created and can be copied.
            Notification::make()
                ->title('לא נשלחה הודעה')
                ->body(implode('; ', $skipped).' — הקישור נוצר, אפשר להעתיק אותו ידנית בכפתור "העתקת קישור לכרטיס".')
                ->warning()->persistent()->send();
        }
    }

    /**
     * The customer 360° view — everything about one customer on a single page:
     * details, subscriptions, invoices, sites, tickets and saved cards.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn ($state): string => Money::ils((int) $state);

        return $infolist->schema([
            // Onboarding checklist — auto-detected steps plus manual ticks.
            // Collapsed once everything is done, prominent while it isn't.
            InfoSection::make('צ׳ק-ליסט קליטה')
                ->icon('heroicon-o-clipboard-document-check')
                ->collapsible()
                ->collapsed(function (Customer $record): bool {
                    $progress = app(OnboardingChecklist::class)->progress($record);

                    return $progress['done'] >= $progress['total'];
                })
                ->schema([
                    ViewEntry::make('onboarding')
                        ->hiddenLabel()
                        ->view('filament.customers.onboarding-checklist'),
                ]),

            InfoSection::make('פרטי לקוח')
                ->icon('heroicon-o-identification')
                ->schema([
                    TextEntry::make('name')->label('שם'),
                    TextEntry::make('contact_name')->label('איש קשר')->placeholder('—'),
                    TextEntry::make('business_number')->label('ח.פ / עוסק')->placeholder('—'),
                    TextEntry::make('business_type')->label('סוג עוסק')->badge(),
                    TextEntry::make('vat_exempt')->label('מע״מ')->formatStateUsing(fn ($state): string => $state ? 'פטור' : 'חייב'),
                    TextEntry::make('email')->label('אימייל')->copyable()->placeholder('—'),
                    TextEntry::make('phone')->label('טלפון')->copyable()->placeholder('—'),
                    TextEntry::make('address')->label('כתובת')->placeholder('—'),
                    TextEntry::make('payment_method')->label('אמצעי תשלום')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'credit_card' => 'כרטיס אשראי',
                            'standing_order' => 'הוראת קבע',
                            'bank_transfer' => 'העברה בנקאית',
                            'checks' => 'צ׳קים',
                            default => '—',
                        })->placeholder('—'),
                    TextEntry::make('terms_accepted_at')->label('אישור תנאים')->dateTime('d/m/Y H:i')->placeholder('—'),
                    // Stated on the record, because this is the fact that
                    // decides whether the fallback collection has anything to
                    // collect from — and the one the signup flow cannot
                    // guarantee, since the card page comes after the customer
                    // is already saved.
                    TextEntry::make('security_card')
                        ->label('כרטיס ביטחון')
                        ->badge()
                        ->state(fn (Customer $record): string => match (true) {
                            $record->hasActiveCard() => 'כרטיס שמור',
                            $record->card_exempt_at !== null => 'פטור מכרטיס',
                            $record->security_card_terms_at !== null => 'חסר — הלקוח אישר ולא הזין',
                            default => 'אין כרטיס',
                        })
                        ->color(fn (Customer $record): string => match (true) {
                            $record->hasActiveCard() => 'success',
                            $record->card_exempt_at !== null => 'warning',
                            $record->security_card_terms_at !== null => 'danger',
                            default => 'gray',
                        })
                        // The exemption names itself and its author. On the day
                        // a payment does not arrive and there is nothing to
                        // fall back on, this is the line that explains why.
                        ->helperText(fn (Customer $record): ?string => match (true) {
                            $record->hasActiveCard() => null,
                            $record->card_exempt_at !== null => trim(sprintf(
                                '%s · אישר/ה %s ב-%s',
                                $record->card_exempt_reason ?: 'ללא סיבה שנרשמה',
                                $record->cardExemptBy?->name ?? '—',
                                $record->card_exempt_at->format('d/m/Y'),
                            )),
                            $record->security_card_terms_at !== null => 'אם תשלום לא יגיע במועד אין ממה לגבות. ניתן לשלוח קישור להזנת כרטיס מרשימת הלקוחות.',
                            default => null,
                        }),
                    // The signed consent record from /join, viewable inline (team-only route).
                    TextEntry::make('signature_path')->label('חתימה')
                        ->formatStateUsing(fn (): string => 'צפייה בחתימה ↗')
                        ->url(fn (Customer $record): ?string => $record->signature_path
                            ? route('customer.signature', $record) : null, shouldOpenInNewTab: true)
                        ->placeholder('—'),
                    TextEntry::make('signed_pdf_path')->label('כרטיס חתום (PDF)')
                        ->formatStateUsing(fn (): string => 'הורדת הכרטיס ↗')
                        ->url(fn (Customer $record): ?string => $record->signed_pdf_path
                            ? route('customer.card-pdf', $record) : null, shouldOpenInNewTab: true)
                        ->placeholder('—'),
                    TextEntry::make('status')->label('סטטוס')->badge(),
                    TextEntry::make('notes')->label('הערות')->placeholder('—')->columnSpanFull(),
                ])->columns(3),

            InfoSection::make('חשבוניות')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('invoices')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('linet_document_id')->label('מסמך'),
                            TextEntry::make('total_agorot')->label('סכום')->formatStateUsing($money),
                            TextEntry::make('issued_at')->label('הונפק')->dateTime('d/m/Y'),
                            TextEntry::make('pdf_url')->label('PDF')
                                ->url(fn ($state) => $state, shouldOpenInNewTab: true)
                                ->formatStateUsing(fn ($state): string => filled($state) ? 'פתח' : '—'),
                        ])->columns(4),
                ]),

            InfoSection::make('אתרים')
                ->icon('heroicon-o-globe-alt')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('sites')
                        ->hiddenLabel()
                        ->schema([
                            // Clickable — opens the site's monitoring page (uptime,
                            // response times, SSL, recent probes) in a new tab.
                            TextEntry::make('domain')->label('דומיין')
                                ->color('primary')->weight('medium')->icon('heroicon-o-chart-bar')
                                ->url(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record]), shouldOpenInNewTab: true),
                            TextEntry::make('status')->label('סטטוס')->badge(),
                            TextEntry::make('id')->label('ניטור')
                                ->formatStateUsing(fn (): string => 'צפייה בניטור ↗')
                                ->color('primary')
                                ->url(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record]), shouldOpenInNewTab: true),
                        ])->columns(3),
                ]),

            InfoSection::make('פניות')
                ->icon('heroicon-o-lifebuoy')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('recentTickets')
                        ->hiddenLabel()
                        ->schema([
                            // Clickable — opens the full ticket (thread + reply) in a new tab.
                            TextEntry::make('subject')->label('נושא')
                                ->color('primary')->weight('medium')->icon('heroicon-o-arrow-top-right-on-square')
                                ->url(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]), shouldOpenInNewTab: true),
                            TextEntry::make('status')->label('סטטוס')->badge(),
                            TextEntry::make('created_at')->label('נפתחה')->dateTime('d/m/Y'),
                        ])->columns(3),
                ]),

            InfoSection::make('כרטיסים שמורים')
                ->icon('heroicon-o-credit-card')
                ->collapsible()
                // Open Cardcom's hosted card page (embedded in an iframe on our
                // route) to capture a new card — no card data ever reaches us.
                ->headerActions([
                    InfolistAction::make('addCard')
                        ->label('הוספת כרטיס')
                        ->icon('heroicon-o-plus')
                        ->url(fn (Customer $record): string => CardLink::for($record->id), shouldOpenInNewTab: true),
                ])
                ->schema([
                    // Which card actually gets charged, said once and plainly.
                    // A list of cards all marked "פעיל" does not answer it, and
                    // that question is the whole reason somebody opens this
                    // section after a charge failed.
                    TextEntry::make('defaultToken')
                        ->label('הכרטיס שמחויב')
                        ->state(fn (Customer $record): string => $record->defaultToken?->label() ?? 'אין — מנויים בכרטיס לא ייגבו')
                        ->badge()
                        ->color(fn (Customer $record): string => match (true) {
                            $record->defaultToken === null => 'danger',
                            $record->defaultToken->hasExpired() => 'danger',
                            default => 'success',
                        })
                        ->helperText('לשינוי הכרטיס הפעיל או להסרת כרטיס — לשונית "כרטיסי אשראי" למטה.'),
                    RepeatableEntry::make('paymentTokens')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('card_brand')->label('סוג')->placeholder('—'),
                            TextEntry::make('card_last4')->label('4 ספרות אחרונות')
                                ->formatStateUsing(fn ($state): string => filled($state) ? '****'.$state : '—'),
                            // Shown here too, because a card that expired keeps
                            // its "פעיל" status until something replaces it —
                            // the status alone would say the card is fine while
                            // every charge on it is declined.
                            TextEntry::make('expiry')->label('תוקף')
                                ->state(fn (PaymentToken $record): string => $record->expiryLabel() ?? '—')
                                ->badge()
                                ->color(fn (PaymentToken $record): string => $record->hasExpired() ? 'danger' : 'gray'),
                            TextEntry::make('status')->label('סטטוס')->badge(),
                        ])->columns(4)
                        ->placeholder('אין כרטיס שמור — הוסיפו כרטיס בכפתור למעלה.'),
                ]),
        ]);
    }

    /**
     * Whether a chargeable card is on file, answered once for the whole page.
     *
     * A per-row `exists()` would be a query per customer on a list that shows
     * fifty of them, for a column that is on by default.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withExists([
            'paymentTokens as has_active_card' => fn (Builder $query) => $query->chargeable(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ContactsRelationManager::class,
            RelationManagers\SubscriptionsRelationManager::class,
            RelationManagers\SitesRelationManager::class,
            RelationManagers\PaymentTokensRelationManager::class,
            RelationManagers\SiteAgentSubscribersRelationManager::class,
            RelationManagers\NotificationLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
