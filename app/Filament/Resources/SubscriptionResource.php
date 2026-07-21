<?php

namespace App\Filament\Resources;

use App\Enums\BillingInterval;
use App\Enums\SiteStatus;
use App\Enums\SiteType;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Filament\Support\DebtorActions;
use App\Filament\Support\MoneyField;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Subscription;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'מנויים';

    protected static ?string $modelLabel = 'מנוי';

    protected static ?string $pluralModelLabel = 'מנויים';

    protected static ?string $navigationGroup = 'כספים';

    // Lead the finance group — subscriptions are the recurring-revenue engine.
    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('מנוי')
                    ->description('לקוח, תוכנית, אתר ואמצעי תשלום')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('לקוח')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            // Card + site belong to THIS customer only. Changing the
                            // customer clears both so a card/site from the previous
                            // customer can never stay attached (and be charged).
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set): void {
                                $set('token_id', null);
                                $set('site_id', null);
                            }),
                        Forms\Components\Select::make('plan_id')
                            ->label('תוכנית קבועה')
                            ->relationship('plan', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('בחרו מוצר קבוע, או השאירו ריק למנוי חופשי בהתאמה אישית.'),
                        Forms\Components\Select::make('site_id')
                            ->label('אתר')
                            // Only the selected customer's sites — never another
                            // customer's.
                            ->relationship('site', 'domain', fn (Builder $query, Forms\Get $get): Builder => $query->where('customer_id', $get('customer_id')))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Forms\Get $get): bool => blank($get('customer_id')))
                            // Add a new site for THIS customer inline, without leaving
                            // the subscription form. The site is always created under
                            // the selected customer (never anyone else's).
                            ->createOptionForm([
                                Forms\Components\TextInput::make('domain')
                                    ->label('דומיין')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Select::make('site_type')
                                    ->label('סוג אתר')
                                    ->options(SiteType::class)
                                    ->native(false)
                                    ->placeholder('לא סווג — הסוכן יזהה לפי WooCommerce'),
                                Forms\Components\Toggle::make('monitor_enabled')
                                    ->label('ניטור פעיל')
                                    ->default(false),
                            ])
                            ->createOptionUsing(function (array $data, Forms\Get $get): int {
                                $customerId = $get('customer_id');
                                if (blank($customerId)) {
                                    throw ValidationException::withMessages([
                                        'customer_id' => 'בחרו לקוח לפני הוספת אתר.',
                                    ]);
                                }

                                return Site::create([
                                    'customer_id' => $customerId,
                                    'domain' => $data['domain'],
                                    'site_type' => $data['site_type'] ?? null,
                                    'monitor_enabled' => $data['monitor_enabled'] ?? false,
                                    'status' => SiteStatus::Active,
                                ])->getKey();
                            })
                            ->rule(fn (Forms\Get $get): Closure => static function (string $attribute, $value, Closure $fail) use ($get): void {
                                if (filled($value) && ! Site::whereKey($value)->where('customer_id', $get('customer_id'))->exists()) {
                                    $fail('האתר שנבחר אינו שייך ללקוח הזה.');
                                }
                            }),
                        Forms\Components\Select::make('token_id')
                            ->label('כרטיס אשראי')
                            // Only the selected customer's saved cards — you must NOT
                            // be able to attach (and charge) another customer's card.
                            ->options(fn (Forms\Get $get): array => PaymentToken::query()
                                ->where('customer_id', $get('customer_id'))
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (PaymentToken $t): array => [
                                    $t->id => trim(($t->card_brand ? $t->card_brand.' ' : '').'•••• '.($t->card_last4 ?: '----')),
                                ])->all())
                            ->searchable()
                            ->native(false)
                            ->disabled(fn (Forms\Get $get): bool => blank($get('customer_id')))
                            ->helperText('רק כרטיסים של הלקוח שנבחר.')
                            // Defense in depth: reject a submitted card that isn't the customer's.
                            ->rule(fn (Forms\Get $get): Closure => static function (string $attribute, $value, Closure $fail) use ($get): void {
                                if (filled($value) && ! PaymentToken::whereKey($value)->where('customer_id', $get('customer_id'))->exists()) {
                                    $fail('הכרטיס שנבחר אינו שייך ללקוח הזה.');
                                }
                            }),
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(SubscriptionStatus::class)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('מנוי חופשי (התאמה אישית)')
                    ->description('לכל לקוח מנוי משלו — מלאו כאן שם, מחיר ותדירות כשאין תוכנית קבועה מתאימה.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('שם המנוי')
                            ->maxLength(190)
                            ->helperText('לדוגמה: אחסון + תחזוקה חודשית'),
                        Forms\Components\Select::make('billing_interval')
                            ->label('תדירות חיוב')
                            ->options(BillingInterval::class),
                        Forms\Components\Toggle::make('vat_applies')
                            ->label('הוסף מע״מ על המחיר')
                            ->default(true),
                    ])
                    ->columns(2)
                    // Only relevant when no fixed plan is chosen — a free-form subscription.
                    ->visible(fn (Forms\Get $get): bool => blank($get('plan_id')))
                    ->collapsible(),

                Forms\Components\Section::make('תקופה וחיוב')
                    ->schema([
                        Forms\Components\DatePicker::make('current_period_start')
                            ->label('תחילת תקופה'),
                        Forms\Components\DatePicker::make('current_period_end')
                            ->label('סוף תקופה'),
                        Forms\Components\DateTimePicker::make('next_charge_at')
                            ->label('חיוב הבא'),
                        MoneyField::make('price_agorot_override', 'מחיר (₪)')
                            // A plan-less subscription has no plan price to fall back on, so
                            // a price is mandatory — otherwise the scheduler would charge ₪0.
                            ->required(fn (Forms\Get $get): bool => blank($get('plan_id')))
                            ->helperText('חובה למנוי חופשי; בתוכנית קבועה — רק אם סוכם מחיר שונה מהתוכנית.'),
                    ])->columns(2),

                Forms\Components\Section::make('גבייה')
                    ->schema([
                        Forms\Components\TextInput::make('dunning_stage')
                            ->label('שלב גבייה')
                            ->numeric()
                            ->required()
                            ->default(0),
                        Forms\Components\DateTimePicker::make('canceled_at')
                            ->label('בוטל בתאריך'),
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
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('plan_name')
                    ->label('תוכנית')
                    ->state(fn (Subscription $record): string => $record->planName()),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('אתר')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state): string => match ($state) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::Trialing => 'info',
                        SubscriptionStatus::PastDue => 'warning',
                        SubscriptionStatus::Suspended => 'danger',
                        SubscriptionStatus::Canceled => 'gray',
                    }),
                Tables\Columns\TextColumn::make('next_charge_at')
                    ->label('חיוב הבא')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_period_end')
                    ->label('סוף תקופה')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('price_agorot_override')
                    ->label('מחיר מיוחד')
                    ->money('ILS', divideBy: 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('dunning_stage')
                    ->label('שלב גבייה')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('canceled_at')
                    ->label('בוטל בתאריך')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
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
            ->defaultSort('next_charge_at', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(SubscriptionStatus::class),
            ])
            ->actions([
                DebtorActions::chargeNow(),
                DebtorActions::sendCardLink(),
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין מנויים עדיין')
            ->emptyStateDescription('הקימו מנוי חדש דרך "מנוי חדש" בתפריט.');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
