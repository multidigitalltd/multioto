<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\SiteResource;
use App\Filament\Support\SiteActions;
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
            // Cards, not rows — matching the main Sites screen. The card links to
            // the site's page; the common actions are shortcuts on the card.
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('domain')
                        ->label('דומיין')
                        ->weight('bold')
                        ->icon('heroicon-m-globe-alt')
                        ->searchable(),
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('status')->badge()->grow(false),
                        Tables\Columns\TextColumn::make('monitor_enabled')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state ? 'ניטור פעיל' : 'ללא ניטור')
                            ->icon(fn ($state): string => $state ? 'heroicon-m-signal' : 'heroicon-m-signal-slash')
                            ->color(fn ($state): string => $state ? 'success' : 'gray')
                            ->grow(false),
                    ]),
                ])->space(2),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('אתר חדש'),
            ])
            ->actions([
                SiteActions::aiInvestigate()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('מידע וניטור')->icon('heroicon-o-chart-bar')
                        ->url(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record])),
                    SiteActions::proposeMcpAction(),
                    SiteActions::testMcp(),
                    SiteActions::connectionCodes(),
                    SiteActions::downloadPlugin(),
                    SiteActions::generateAgentToken(),
                    Tables\Actions\EditAction::make()->label('עריכה'),
                    Tables\Actions\DeleteAction::make()->label('מחיקה'),
                ])
                    ->label('עוד פעולות')
                    ->icon('heroicon-m-ellipsis-horizontal')
                    ->button()
                    ->color('gray'),
            ]);
    }
}
