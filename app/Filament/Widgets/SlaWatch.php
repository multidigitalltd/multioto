<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard SLA board: open tickets still awaiting a first reply that are either
 * already past their response target (breached) or in the final stretch before
 * it (at risk) — so the team can jump on the ones about to slip.
 */
class SlaWatch extends BaseWidget
{
    protected static ?int $sort = -45;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'SLA — פניות בחריגה / בסיכון';

    /** Ids of open, unanswered tickets that are breached or at risk. */
    private static function watchIds(): array
    {
        return Ticket::query()
            ->where('status', TicketStatus::Open)
            ->whereNull('first_response_at')
            ->get(['id', 'priority', 'status', 'created_at'])
            ->filter(fn (Ticket $t): bool => in_array($t->firstResponseSlaStatus(), ['breached', 'at_risk'], true))
            ->pluck('id')
            ->all();
    }

    public static function canView(): bool
    {
        return self::watchIds() !== [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Ticket::query()->whereKey(self::watchIds())->with('customer'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->prefix('#'),
                Tables\Columns\TextColumn::make('sender')->label('פונה')->weight('bold')
                    ->getStateUsing(fn (Ticket $r): string => $r->senderName()),
                Tables\Columns\TextColumn::make('subject')->label('נושא')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('priority')->label('עדיפות')->badge(),
                Tables\Columns\TextColumn::make('sla')->label('SLA תגובה')->badge()
                    ->getStateUsing(fn (Ticket $r): string => $r->firstResponseSlaStatus() === 'breached' ? 'חריגה' : 'בסיכון')
                    ->color(fn (Ticket $r): string => $r->firstResponseSlaStatus() === 'breached' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')->label('פתוח מ')->since(),
                Tables\Columns\TextColumn::make('due')->label('יעד תגובה')
                    ->getStateUsing(fn (Ticket $r): string => $r->firstResponseDueAt()->format('d/m/Y H:i')),
            ])
            ->defaultSort('created_at', 'asc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('פתח פנייה')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Ticket $r): string => TicketResource::getUrl('edit', ['record' => $r])),
            ])
            ->paginated([5]);
    }
}
