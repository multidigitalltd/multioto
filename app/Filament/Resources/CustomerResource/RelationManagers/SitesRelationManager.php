<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manage a customer's sites (domain, monitoring, status) directly from the
 * customer card.
 */
class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = 'אתרים';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('domain')->label('דומיין')->required()->maxLength(255),
            Forms\Components\TextInput::make('monitor_url')->label('כתובת לניטור')->url()->maxLength(255),
            Forms\Components\Toggle::make('monitor_enabled')->label('ניטור פעיל'),
            Forms\Components\TextInput::make('hosting_ref')->label('מזהה אחסון (FlyWP)')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('domain')
            ->columns([
                Tables\Columns\TextColumn::make('domain')->label('דומיין')->searchable(),
                Tables\Columns\IconColumn::make('monitor_enabled')->label('ניטור')->boolean(),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('אתר חדש'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\DeleteAction::make()->label('מחיקה'),
            ]);
    }
}
