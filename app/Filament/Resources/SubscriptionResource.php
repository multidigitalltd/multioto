<?php

namespace App\Filament\Resources;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'מנויים';

    protected static ?string $modelLabel = 'מנוי';

    protected static ?string $pluralModelLabel = 'מנויים';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 2;

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
                            ->required(),
                        Forms\Components\Select::make('plan_id')
                            ->label('תוכנית')
                            ->relationship('plan', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('site_id')
                            ->label('אתר')
                            ->relationship('site', 'domain')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('token_id')
                            ->label('כרטיס אשראי')
                            ->relationship('token', 'id')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(SubscriptionStatus::class)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('תקופה וחיוב')
                    ->schema([
                        Forms\Components\DatePicker::make('current_period_start')
                            ->label('תחילת תקופה'),
                        Forms\Components\DatePicker::make('current_period_end')
                            ->label('סוף תקופה'),
                        Forms\Components\DateTimePicker::make('next_charge_at')
                            ->label('חיוב הבא'),
                        Forms\Components\TextInput::make('price_agorot_override')
                            ->label('מחיר מיוחד (אגורות)')
                            ->helperText('100 אגורות = ₪1')
                            ->numeric(),
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
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('תוכנית')
                    ->searchable()
                    ->sortable(),
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
