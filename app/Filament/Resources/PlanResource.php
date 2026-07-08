<?php

namespace App\Filament\Resources;

use App\Enums\BillingInterval;
use App\Filament\Resources\PlanResource\Pages;
use App\Filament\Support\MoneyField;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'תוכניות';

    protected static ?string $modelLabel = 'תוכנית';

    protected static ?string $pluralModelLabel = 'תוכניות';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('פרטי התוכנית')
                    ->description('שם, מחיר ותנאי חיוב')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('שם התוכנית')
                            ->required()
                            ->maxLength(255),
                        MoneyField::make('price_agorot', 'מחיר (₪ לחודש)')
                            ->required(),
                        Forms\Components\Select::make('billing_interval')
                            ->label('תדירות חיוב')
                            ->options(BillingInterval::class)
                            ->required(),
                        Forms\Components\Toggle::make('vat_applies')
                            ->label('חל מע״מ')
                            ->inline(false)
                            ->required(),
                        Forms\Components\Toggle::make('active')
                            ->label('פעילה')
                            ->inline(false)
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label('תיאור')
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
                    ->label('שם התוכנית')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('price_agorot')
                    ->label('מחיר')
                    ->money('ILS', divideBy: 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_interval')
                    ->label('תדירות חיוב')
                    ->badge(),
                Tables\Columns\IconColumn::make('vat_applies')
                    ->label('חל מע״מ')
                    ->boolean(),
                Tables\Columns\IconColumn::make('active')
                    ->label('פעילה')
                    ->boolean(),
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
            ->defaultSort('name', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('billing_interval')
                    ->label('תדירות חיוב')
                    ->options(BillingInterval::class),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('פעילה'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין תוכניות עדיין')
            ->emptyStateDescription('הקימו תוכנית חדשה דרך "תוכנית חדשה" בתפריט.');
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
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
