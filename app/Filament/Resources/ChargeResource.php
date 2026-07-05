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

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('subscription_id')
                    ->relationship('subscription', 'id')
                    ->required(),
                Forms\Components\TextInput::make('amount_agorot')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('vat_agorot')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('total_agorot')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('currency')
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options(ChargeStatus::class)
                    ->required(),
                Forms\Components\TextInput::make('attempt_number')
                    ->required()
                    ->numeric()
                    ->default(1),
                Forms\Components\TextInput::make('cardcom_transaction_id'),
                Forms\Components\TextInput::make('cardcom_response_code'),
                Forms\Components\TextInput::make('failure_reason'),
                Forms\Components\DatePicker::make('period_start')
                    ->required(),
                Forms\Components\DatePicker::make('period_end')
                    ->required(),
                Forms\Components\DateTimePicker::make('charged_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subscription.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_agorot')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vat_agorot')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('attempt_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cardcom_transaction_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cardcom_response_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('failure_reason')
                    ->searchable(),
                Tables\Columns\TextColumn::make('period_start')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_end')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('charged_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
