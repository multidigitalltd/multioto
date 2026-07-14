<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard reminder: the signed-in team member's open tasks, soonest due
 * first, overdue ones flagged red. One click opens the task to update it.
 */
class MyTasks extends BaseWidget
{
    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'המשימות שלי';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Task::query()
                    ->open()
                    ->whereHas('assignees', fn ($q) => $q->whereKey(auth()->id()))
                    ->with('customer')
                    ->orderByRaw('due_at is null')
                    ->orderBy('due_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('משימה')->wrap()->weight('medium'),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->placeholder('—'),
                Tables\Columns\TextColumn::make('priority')->label('עדיפות')->badge(),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('due_at')->label('יעד')->dateTime('d/m/Y')->placeholder('—')
                    ->color(fn (Task $record): ?string => $record->due_at && $record->due_at->isPast() ? 'danger' : null),
            ])
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('edit', ['record' => $record]))
            ->paginated([5])
            ->emptyStateHeading('אין משימות פתוחות 🎉');
    }
}
