<?php

namespace App\Filament\Resources;

use App\Enums\ChargeStatus;
use App\Filament\Resources\ChargeResource\Pages;
use App\Models\Charge;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChargeResource extends Resource
{
    protected static ?string $model = Charge::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'חיובים';

    protected static ?string $modelLabel = 'חיוב';

    protected static ?string $pluralModelLabel = 'חיובים';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('מנוי וסכום')
                    ->schema([
                        Forms\Components\Select::make('subscription_id')
                            ->label('מנוי')
                            ->relationship('subscription', 'id')
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('amount_agorot')
                            ->label('סכום (אגורות)')
                            ->helperText('100 אגורות = ₪1')
                            ->required()
                            ->numeric(),
                        Forms\Components\TextInput::make('vat_agorot')
                            ->label('מע״מ (אגורות)')
                            ->helperText('100 אגורות = ₪1')
                            ->required()
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('total_agorot')
                            ->label('סה״כ (אגורות)')
                            ->helperText('100 אגורות = ₪1')
                            ->required()
                            ->numeric(),
                        Forms\Components\TextInput::make('currency')
                            ->label('מטבע')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('תוצאת חיוב')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(ChargeStatus::class)
                            ->required(),
                        Forms\Components\TextInput::make('attempt_number')
                            ->label('מספר ניסיון')
                            ->required()
                            ->numeric()
                            ->default(1),
                        Forms\Components\TextInput::make('cardcom_transaction_id')
                            ->label('מזהה עסקה (קארדקום)'),
                        Forms\Components\TextInput::make('cardcom_response_code')
                            ->label('קוד תגובה'),
                        Forms\Components\TextInput::make('failure_reason')
                            ->label('סיבת כישלון'),
                        Forms\Components\DateTimePicker::make('charged_at')
                            ->label('חויב בתאריך'),
                    ])->columns(2),

                Forms\Components\Section::make('תקופה')
                    ->schema([
                        Forms\Components\DatePicker::make('period_start')
                            ->label('תחילת תקופה')
                            ->required(),
                        Forms\Components\DatePicker::make('period_end')
                            ->label('סוף תקופה')
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subscription.customer.name')
                    ->label('לקוח')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->label('סה״כ')
                    ->money('ILS', divideBy: 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Succeeded => 'success',
                        ChargeStatus::Pending => 'warning',
                        ChargeStatus::Failed => 'danger',
                    }),
                Tables\Columns\TextColumn::make('attempt_number')
                    ->label('ניסיון')
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_start')
                    ->label('תחילת תקופה')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('charged_at')
                    ->label('חויב')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cardcom_transaction_id')
                    ->label('מזהה עסקה (קארדקום)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('cardcom_response_code')
                    ->label('קוד תגובה')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('failure_reason')
                    ->label('סיבת כישלון')
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
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(ChargeStatus::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין חיובים עדיין')
            ->emptyStateDescription('חיובים נוצרים אוטומטית על ידי מנוע החיוב.');
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
            'index' => Pages\ListCharges::route('/'),
            'create' => Pages\CreateCharge::route('/create'),
            'edit' => Pages\EditCharge::route('/{record}/edit'),
        ];
    }
}
