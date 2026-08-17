<?php

namespace App\Filament\Resources\SiteResource\RelationManagers;

use App\Models\SiteChange;
use App\Services\Agent\SiteActionBatchRunner;
use App\Services\Automation\ApprovalGate;
use Filament\Notifications\Notification;
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
            ->actions([
                // Propose the recorded inverse action through the same approval
                // gate — a rollback is itself a manager-approved change. Shown
                // only when the change is still applied and carries a recipe.
                Tables\Actions\Action::make('revert')
                    ->label('שחזר')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (SiteChange $record): bool => $record->isRevertable()
                        && (auth()->user()?->isAdmin() ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('שחזור שינוי')
                    ->modalDescription(fn (SiteChange $record): string => "לשחזר: {$record->summary}? הפעולה תישלח לאישור מנהל לפני ביצוע.")
                    ->modalSubmitActionLabel('שלח לאישור')
                    ->action(function (SiteChange $record, ApprovalGate $gate): void {
                        $gate->propose(
                            type: 'site_action',
                            summary: "↩️ שחזור שינוי באתר {$record->site->domain}\n{$record->summary}\nכלי שחזור: {$record->revert_tool}",
                            payload: [
                                'site_id' => $record->site_id,
                                'tool' => $record->revert_tool,
                                'arguments' => $record->revert_arguments ?? [],
                                'reverts_change_id' => $record->id,
                            ],
                            customerId: $record->site->customer_id,
                            proposedBy: 'team',
                        );

                        Notification::make()->title('בקשת השחזור נשלחה לאישור')->success()->send();
                    }),

                // A change that arrived as part of a batch can be undone with
                // the rest of it. Reverting a twenty-product sale one row at a
                // time leaves the shop half on sale for as long as it takes,
                // and leaves whoever is clicking to remember where they got to.
                Tables\Actions\Action::make('revertBatch')
                    ->label('שחזר את כל האצווה')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (SiteChange $record): bool => $record->batchSize() > 1
                        && $record->isRevertable()
                        && (auth()->user()?->isAdmin() ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('שחזור אצווה שלמה')
                    ->modalDescription(fn (SiteChange $record): string => 'לשחזר את כל '.$record->batchSize()
                        .' השינויים שבוצעו יחד בפעולה הזו? הבקשה תישלח לאישור מנהל לפני ביצוע.')
                    ->modalSubmitActionLabel('שלח לאישור')
                    ->action(function (SiteChange $record, ApprovalGate $gate, SiteActionBatchRunner $batches): void {
                        $plan = $batches->revertPlan($record->pendingAction);

                        if ($plan['calls'] === []) {
                            Notification::make()
                                ->title('אין מה לשחזר — לאף שינוי באצווה אין פעולה הפוכה שמורה')
                                ->warning()->send();

                            return;
                        }

                        // The skipped count is said out loud: "restore
                        // everything" must never quietly mean "restore most of
                        // it", or the shop is left in a state nobody described.
                        $note = $plan['skipped'] > 0
                            ? "\n(".$plan['skipped'].' שינויים באצווה ללא פעולה הפוכה שמורה — הם לא ישוחזרו.)'
                            : '';

                        $gate->propose(
                            type: 'site_action_batch',
                            summary: '↩️ שחזור אצווה באתר '.$record->site->domain."\n"
                                .count($plan['calls'])." שינויים יבוטלו בסדר הפוך.{$note}",
                            payload: ['site_id' => $record->site_id, 'calls' => $plan['calls']],
                            customerId: $record->site->customer_id,
                            proposedBy: 'team',
                        );

                        Notification::make()->title('בקשת שחזור האצווה נשלחה לאישור')->success()->send();
                    }),
            ])
            ->emptyStateHeading('עדיין לא בוצעו שינויים באתר הזה');
    }
}
