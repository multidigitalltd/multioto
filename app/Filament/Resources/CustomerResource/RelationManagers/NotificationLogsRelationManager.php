<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\NotificationLog;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only tab on the customer card: every OUTBOUND message the customer
 * received (email/WhatsApp) — welcome, payment links, card links, dunning,
 * domain-renewal reminders, etc. Distinct from the ticket conversation; this is
 * the notification audit trail. Nothing is created/edited here.
 */
class NotificationLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'notificationLogs';

    protected static ?string $title = 'הודעות יוצאות';

    protected static ?string $icon = 'heroicon-o-paper-airplane';

    /** @return array<string, string> */
    private static function channelLabels(): array
    {
        return ['email' => 'אימייל', 'whatsapp' => 'וואטסאפ'];
    }

    /** @return array<string, string> */
    private static function statusLabels(): array
    {
        return ['sent' => 'נשלח', 'queued' => 'בתור', 'failed' => 'נכשל'];
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        $channels = self::channelLabels();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('נשלח')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('סוג')
                    ->badge(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('ערוץ')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => $channels[$state] ?? $state),
                Tables\Columns\TextColumn::make('subject')
                    ->label('נושא')
                    ->placeholder('—')
                    ->limit(45),
                // Reported by the mail provider, not by us. Blank means it has
                // not told us yet — which is not the same as "did not arrive".
                Tables\Columns\TextColumn::make('delivered_at')
                    ->label('נמסר')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->label('נפתח')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'queued' => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('ערוץ')
                    ->options($channels),
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(self::statusLabels()),
            ])
            // Read-only: no create/edit/delete — this is a history, not an editor.
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('צפייה')
                    ->modalHeading('הודעה שנשלחה ללקוח')
                    ->infolist([
                        TextEntry::make('sent_at')->label('נשלח')->dateTime('d/m/Y H:i'),
                        TextEntry::make('type')->label('סוג')->badge(),
                        TextEntry::make('channel')->label('ערוץ')
                            ->formatStateUsing(fn (string $state): string => $channels[$state] ?? $state),
                        TextEntry::make('recipient')->label('נמען')->placeholder('—'),
                        TextEntry::make('delivered_at')->label('נמסר')->dateTime('d/m/Y H:i')
                            ->placeholder('טרם דווח על ידי ספק הדואר'),
                        TextEntry::make('opened_at')->label('נפתח')->dateTime('d/m/Y H:i')
                            ->placeholder('—')
                            // Pixel-based, so it is a floor and not an exact number:
                            // a customer who blocks images reads as "not opened".
                            ->helperText(fn (NotificationLog $record): ?string => $record->open_count > 1
                                ? "נפתח {$record->open_count} פעמים"
                                : null),
                        TextEntry::make('bounced_at')->label('חזרה')->dateTime('d/m/Y H:i')
                            ->visible(fn (NotificationLog $record): bool => $record->bounced_at !== null)
                            ->color('danger'),
                        TextEntry::make('subject')->label('נושא')->placeholder('—'),
                        TextEntry::make('body')->label('תוכן')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('error')->label('שגיאה')
                            ->visible(fn (NotificationLog $record): bool => filled($record->error))
                            ->color('danger')->columnSpanFull(),
                    ]),
            ])
            ->emptyStateHeading('טרם נשלחו הודעות ללקוח')
            ->emptyStateIcon('heroicon-o-paper-airplane');
    }
}
