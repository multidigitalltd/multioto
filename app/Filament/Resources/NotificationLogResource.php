<?php

namespace App\Filament\Resources;

use App\Enums\NotificationType;
use App\Filament\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only audit trail of every message the system sent to a customer — one
 * place to answer "did the reminder actually go out, and what did it say?".
 * The team never creates or edits rows here; they are written automatically by
 * the send jobs.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'הודעות יוצאות';

    protected static ?string $modelLabel = 'הודעה יוצאת';

    protected static ?string $pluralModelLabel = 'הודעות יוצאות';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 6;

    /** Written only by the send jobs — never created by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer');
    }

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

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'sent' => 'success',
            'queued' => 'warning',
            default => 'danger',
        };
    }

    public static function table(Table $table): Table
    {
        $channels = self::channelLabels();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('נשלח')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')
                    ->placeholder('—')
                    ->searchable()
                    ->url(fn (NotificationLog $record): ?string => $record->customer_id
                        ? CustomerResource::getUrl('view', ['record' => $record->customer_id]) : null),
                Tables\Columns\TextColumn::make('type')
                    ->label('סוג')
                    ->badge(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('ערוץ')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => $channels[$state] ?? $state),
                Tables\Columns\TextColumn::make('recipient')
                    ->label('נמען')
                    ->searchable()
                    ->toggleable()
                    ->limit(28),
                Tables\Columns\TextColumn::make('subject')
                    ->label('נושא')
                    ->placeholder('—')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('סוג')
                    ->options(NotificationType::class),
                Tables\Filters\SelectFilter::make('channel')
                    ->label('ערוץ')
                    ->options($channels),
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(self::statusLabels()),
                Tables\Filters\Filter::make('sent_at')
                    ->form([
                        DatePicker::make('from')->label('מתאריך'),
                        DatePicker::make('until')->label('עד תאריך'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('sent_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('sent_at', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('צפייה'),
            ])
            ->emptyStateHeading('עדיין לא נשלחו הודעות')
            ->emptyStateDescription('כל מייל/וואטסאפ שיישלח ללקוח יופיע כאן.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $channels = self::channelLabels();

        return $infolist->schema([
            InfoSection::make()
                ->schema([
                    TextEntry::make('sent_at')->label('נשלח')->dateTime('d/m/Y H:i'),
                    TextEntry::make('customer.name')->label('לקוח')->placeholder('—'),
                    TextEntry::make('type')->label('סוג')->badge(),
                    TextEntry::make('channel')->label('ערוץ')
                        ->formatStateUsing(fn (string $state): string => $channels[$state] ?? $state),
                    TextEntry::make('recipient')->label('נמען')->copyable()->placeholder('—'),
                    TextEntry::make('status')->label('סטטוס')
                        ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state),
                    TextEntry::make('error')->label('שגיאה')->placeholder('—')->columnSpanFull(),
                ])->columns(3),
            InfoSection::make('תוכן ההודעה')
                ->schema([
                    TextEntry::make('subject')->label('נושא')->placeholder('—'),
                    TextEntry::make('body')->label('תוכן')->prose()->copyable()->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
        ];
    }
}
