<?php

namespace App\Filament\Resources;

use App\Enums\ActionStatus;
use App\Filament\Resources\PendingActionResource\Pages;
use App\Models\PendingAction;
use App\Services\Automation\ApprovalGate;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The approval-gate inbox: every action the AI/automation proposed, awaiting
 * (or after) the owner's decision. WhatsApp "אשר/דחה" is the fast path; this
 * screen is the fallback and the full audit trail.
 */
class PendingActionResource extends Resource
{
    protected static ?string $model = PendingAction::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'אישורי אוטומציה';

    protected static ?string $modelLabel = 'פעולה לאישור';

    protected static ?string $pluralModelLabel = 'אישורי אוטומציה';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?int $navigationSort = 5;

    /** Hebrew names for action types. */
    public const TYPE_LABELS = [
        'ticket_reply' => 'תשובה ללקוח',
        'site_fix' => 'תיקון אתר',
        'site_action' => 'פעולת AI באתר',
        'monitoring_report' => 'דוח ניטור ללקוח',
    ];

    public static function getNavigationBadge(): ?string
    {
        $pending = PendingAction::where('status', ActionStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('סוג')
                    ->formatStateUsing(fn (string $state) => self::TYPE_LABELS[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->placeholder('—'),
                Tables\Columns\TextColumn::make('summary')
                    ->label('מה יבוצע')
                    ->limit(90)
                    ->tooltip(fn (PendingAction $record): string => Str::limit($record->summary, 500))
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('הוצע')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('error')->label('שגיאה')->limit(60)->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('סטטוס')->options(ActionStatus::class)
                    ->default(ActionStatus::Pending->value),
            ])
            ->actions([
                // Edit the AI's proposed reply, then send it. Only for text
                // replies to the customer (ticket_reply) — the operator can tweak
                // wording before it goes out, without leaving the approvals screen.
                Tables\Actions\Action::make('editReply')
                    ->label('ערוך ושלח')
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->visible(fn (PendingAction $record): bool => $record->type === 'ticket_reply'
                        && $record->status === ActionStatus::Pending)
                    ->modalHeading('עריכת תשובת ה-AI ושליחה ללקוח')
                    ->modalSubmitActionLabel('שלח ללקוח')
                    ->fillForm(fn (PendingAction $record): array => [
                        'reply' => (string) data_get($record->payload, 'reply', ''),
                    ])
                    ->form([
                        Textarea::make('reply')
                            ->label('תוכן התשובה ללקוח')
                            ->helperText('הטקסט שיישלח ללקוח בערוץ הפנייה. ניתן לערוך לפני השליחה.')
                            ->rows(10)
                            ->required(),
                    ])
                    ->action(function (PendingAction $record, array $data, ApprovalGate $gate): void {
                        self::sendEditedReply($record, (string) $data['reply'], $gate);
                    }),
                Tables\Actions\Action::make('approve')
                    ->label('אשר ובצע')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PendingAction $record): bool => $record->status === ActionStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading('אישור וביצוע הפעולה')
                    ->modalDescription(fn (PendingAction $record): string => Str::limit($record->summary, 400))
                    ->action(function (PendingAction $record, ApprovalGate $gate): void {
                        $result = $gate->approve($record->fresh());
                        Notification::make()->title($result)
                            ->{$record->fresh()->status === ActionStatus::Executed ? 'success' : 'warning'}()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('דחה')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PendingAction $record): bool => $record->status === ActionStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (PendingAction $record, ApprovalGate $gate): void {
                        Notification::make()->title($gate->reject($record->fresh()))->send();
                    }),
            ])
            ->bulkActions([
                // Clear a batch of pending approvals in one go — approve & run, or
                // reject. Only pending rows are acted on; the rest are skipped.
                Tables\Actions\BulkAction::make('approveSelected')
                    ->label('אשר ובצע')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('אישור וביצוע מספר פעולות')
                    ->modalDescription('כל הפעולות הממתינות שנבחרו יאושרו ויבוצעו. פעולות שכבר טופלו יידלגו.')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, ApprovalGate $gate) => self::runBatch($records, fn ($a) => $gate->approve($a), 'אושרו ובוצעו')),
                Tables\Actions\BulkAction::make('rejectSelected')
                    ->label('דחה')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('דחיית מספר פעולות')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, ApprovalGate $gate) => self::runBatch($records, fn ($a) => $gate->reject($a), 'נדחו')),
            ]);
    }

    /**
     * Persist the operator's edited reply onto the action, then approve it so it
     * ships to the customer. The summary (the audit line + WhatsApp body) is
     * rebuilt to reflect what was actually sent, not the original AI text.
     */
    private static function sendEditedReply(PendingAction $record, string $reply, ApprovalGate $gate): void
    {
        $reply = trim($reply);

        if ($reply === '') {
            Notification::make()->title('אין תוכן לשליחה')->warning()->send();

            return;
        }

        $payload = $record->payload ?? [];
        $payload['reply'] = $reply;
        $payload['edited_by_operator'] = true;

        // The summary is "…:\n\n<reply>" — replace the body after the blank line
        // so the trail shows the edited text, keeping the original header.
        $summary = Str::contains($record->summary, "\n\n")
            ? Str::before($record->summary, "\n\n")."\n\n".$reply
            : $reply;

        $record->update(['payload' => $payload, 'summary' => $summary]);

        $result = $gate->approve($record->fresh());
        Notification::make()->title($result)
            ->{$record->fresh()->status === ActionStatus::Executed ? 'success' : 'warning'}()
            ->send();
    }

    /**
     * Apply $handler to each still-pending action in the selection and report a
     * single summary (done / skipped / failed) — one notification for the batch.
     *
     * @param  Collection<int, PendingAction>  $records
     */
    private static function runBatch(Collection $records, callable $handler, string $verb): void
    {
        $done = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($records as $action) {
            if ($action->fresh()->status !== ActionStatus::Pending) {
                $skipped++;

                continue;
            }

            try {
                $handler($action->fresh());
                // approve() swallows execution errors and marks the row Failed
                // (returning a message, not throwing), so judge by the final
                // status — not by the call returning.
                $final = $action->fresh()->status;
                in_array($final, [ActionStatus::Executed, ActionStatus::Rejected], true) ? $done++ : $failed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        Notification::make()
            ->title("{$done} פעולות {$verb}")
            ->body(trim(($skipped ? "{$skipped} דילוג (לא ממתינות). " : '').($failed ? "{$failed} נכשלו." : '')) ?: null)
            ->{$failed ? 'warning' : 'success'}()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPendingActions::route('/'),
        ];
    }
}
