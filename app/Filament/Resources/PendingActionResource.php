<?php

namespace App\Filament\Resources;

use App\Enums\ActionStatus;
use App\Filament\Resources\PendingActionResource\Pages;
use App\Models\PendingAction;
use App\Services\Automation\ApprovalGate;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
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
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPendingActions::route('/'),
        ];
    }
}
