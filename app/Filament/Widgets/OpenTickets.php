<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard inbox: every open/pending ticket, newest first — one click opens
 * the conversation view. This is the "what needs me right now" list.
 */
class OpenTickets extends BaseWidget
{
    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'פניות פתוחות — לטיפול';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Ticket::query()
                    ->with('customer')
                    ->whereIn('status', [TicketStatus::Open, TicketStatus::Pending])
                    ->latest('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->placeholder('לא מזוהה'),
                Tables\Columns\TextColumn::make('subject')->label('נושא')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('channel')->label('ערוץ')->badge(),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('נפתחה')->since(),
            ])
            ->recordUrl(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]))
            ->paginated([10])
            ->emptyStateHeading('אין פניות פתוחות 🎉');
    }
}
