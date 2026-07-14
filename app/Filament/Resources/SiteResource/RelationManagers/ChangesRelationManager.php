<?php

namespace App\Filament\Resources\SiteResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The site's change journal ("sandbox") — a read-only history of every action
 * the agent applied to this site, each carrying the data needed to roll it
 * back. The rollback itself is executed by the MCP layer (a later phase); here
 * the team sees exactly what changed and when.
 */
class ChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'יומן שינויים';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('summary')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('מתי')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('summary')->label('שינוי')->wrap()->limit(80),
                Tables\Columns\TextColumn::make('tool')->label('כלי')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('initiated_by')->label('יזם')->placeholder('—')->toggleable(),
            ])
            ->emptyStateHeading('עדיין לא בוצעו שינויים באתר הזה');
    }
}
