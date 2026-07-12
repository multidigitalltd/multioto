<?php

namespace App\Filament\Widgets;

use App\Enums\ActionStatus;
use App\Filament\Resources\PendingActionResource;
use App\Models\PendingAction;
use App\Services\Automation\ApprovalGate;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Str;

/**
 * Dashboard: automation actions awaiting the owner's decision — approve/reject
 * right here, exactly like the WhatsApp "אשר/דחה" flow.
 */
class PendingApprovals extends BaseWidget
{
    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'ממתין לאישורך';

    public static function canView(): bool
    {
        return PendingAction::where('status', ActionStatus::Pending)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PendingAction::query()
                    ->with('customer')
                    ->where('status', ActionStatus::Pending)
                    ->latest('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('type')->label('סוג')
                    ->formatStateUsing(fn (string $state) => PendingActionResource::TYPE_LABELS[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->placeholder('—'),
                Tables\Columns\TextColumn::make('summary')->label('מה יבוצע')->limit(80)
                    ->tooltip(fn (PendingAction $record): string => Str::limit($record->summary, 500))->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('הוצע')->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('אשר')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PendingAction $record): string => Str::limit($record->summary, 400))
                    ->action(function (PendingAction $record, ApprovalGate $gate): void {
                        $result = $gate->approve($record->fresh());
                        Notification::make()->title($result)
                            ->{$record->fresh()->status === ActionStatus::Executed ? 'success' : 'warning'}()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('דחה')->icon('heroicon-o-x-circle')->color('danger')
                    ->requiresConfirmation()
                    ->action(function (PendingAction $record, ApprovalGate $gate): void {
                        Notification::make()->title($gate->reject($record->fresh()))->send();
                    }),
            ])
            ->paginated([5]);
    }
}
