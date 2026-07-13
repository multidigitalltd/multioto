<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\SiteResource;
use App\Models\Site;
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

    // Keep the table interactive on the customer's view page too (Filament makes
    // relation managers read-only on ViewRecord pages by default).
    public function isReadOnly(): bool
    {
        return false;
    }

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
                // Open the site's monitoring page (uptime, response times, SSL,
                // recent probes) straight from the customer card.
                Tables\Actions\Action::make('monitor')
                    ->label('ניטור')
                    ->icon('heroicon-o-chart-bar')
                    ->color('gray')
                    ->url(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\DeleteAction::make()->label('מחיקה'),
            ]);
    }
}
