<?php

namespace App\Filament\Resources;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Customer;
use App\Services\Notifications\CardCaptureLinkSender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

class CustomerResource extends Resource
{
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
            ])
            ->actions([
                Tables\Actions\Action::make('sendCardLink')
                    ->label('קישור לכרטיס')
                    ->icon('heroicon-o-credit-card')
                    ->visible(fn (Customer $record): bool => filled($record->phone ?? $record->email)
                        && $record->subscriptions()->whereNot('status', SubscriptionStatus::Canceled)->exists())
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

                        // The subscription may have been canceled between render and click.
                        if ($subscription === null) {
                            Notification::make()
                                ->title('ללקוח אין מנוי פעיל לשליחת קישור')
                                ->warning()
                                ->send();

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
                    ->fillForm(fn (Customer $record): array => [
                        'link' => URL::temporarySignedRoute(
                            'billing.update-card',
                            now()->addHours((int) config('billing.card_update_link_ttl_hours')),
                            ['customer' => $record->id],
                        ),
                    ])
                    ->form([
                        Forms\Components\TextInput::make('link')
                            ->label('קישור')
                            ->readOnly()
                            ->columnSpanFull(),
                    ])
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
        if ($result['sent'] !== [] && $result['failed'] === []) {
            Notification::make()->title('הקישור נשלח: '.implode(', ', $result['sent']).' ✓')->success()->send();
        } elseif ($result['sent'] !== []) {
            Notification::make()
                ->title('נשלח חלקית')
                ->body('נשלח: '.implode(', ', $result['sent']).'. נכשל: '.implode('; ', $result['failed']))
                ->warning()->persistent()->send();
        } else {
            Notification::make()
                ->title('השליחה נכשלה')
                ->body(implode('; ', $result['failed']).' — אפשר להעתיק את הקישור ידנית בכפתור "העתקת קישור לכרטיס".')
                ->danger()->persistent()->send();
        }
    }

    /**
     * The customer 360° view — everything about one customer on a single page:
     * details, subscriptions, invoices, sites, tickets and saved cards.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn ($state): string => '₪'.number_format(((int) $state) / 100, 2);

        return $infolist->schema([
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
                            default => '—',
                        })->placeholder('—'),
                    TextEntry::make('terms_accepted_at')->label('אישור תנאים')->dateTime('d/m/Y H:i')->placeholder('—'),
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
                            TextEntry::make('domain')->label('דומיין'),
                            TextEntry::make('status')->label('סטטוס')->badge(),
                            TextEntry::make('monitor_url')->label('ניטור')->placeholder('—'),
                        ])->columns(3),
                ]),

            InfoSection::make('פניות')
                ->icon('heroicon-o-lifebuoy')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('tickets')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('subject')->label('נושא'),
                            TextEntry::make('status')->label('סטטוס')->badge(),
                            TextEntry::make('created_at')->label('נפתחה')->dateTime('d/m/Y'),
                        ])->columns(3),
                ]),

            InfoSection::make('כרטיסים שמורים')
                ->icon('heroicon-o-credit-card')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('paymentTokens')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('card_brand')->label('סוג')->placeholder('—'),
                            TextEntry::make('card_last4')->label('4 ספרות אחרונות')
                                ->formatStateUsing(fn ($state): string => filled($state) ? '****'.$state : '—'),
                            TextEntry::make('status')->label('סטטוס')->badge(),
                        ])->columns(3),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubscriptionsRelationManager::class,
            RelationManagers\SitesRelationManager::class,
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
