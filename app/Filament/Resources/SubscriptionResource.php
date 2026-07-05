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

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->required(),
                Forms\Components\Select::make('plan_id')
                    ->relationship('plan', 'name')
                    ->required(),
                Forms\Components\Select::make('site_id')
                    ->relationship('site', 'id'),
                Forms\Components\Select::make('token_id')
                    ->relationship('token', 'id'),
                Forms\Components\Select::make('status')
                    ->options(SubscriptionStatus::class)
                    ->required(),
                Forms\Components\DatePicker::make('current_period_start'),
                Forms\Components\DatePicker::make('current_period_end'),
                Forms\Components\DateTimePicker::make('next_charge_at'),
                Forms\Components\TextInput::make('price_agorot_override')
                    ->numeric(),
                Forms\Components\TextInput::make('dunning_stage')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\DateTimePicker::make('canceled_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('site.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('token.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('current_period_start')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_period_end')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('next_charge_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_agorot_override')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dunning_stage')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('canceled_at')
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
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
