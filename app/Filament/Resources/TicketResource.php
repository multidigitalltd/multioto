<?php

namespace App\Filament\Resources;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\Ticket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'פניות';

    protected static ?string $modelLabel = 'פנייה';

    protected static ?string $pluralModelLabel = 'פניות';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function getNavigationBadge(): ?string
    {
        $count = Ticket::query()->where('status', TicketStatus::Open)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('פנייה')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('לקוח')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('channel')
                            ->label('ערוץ')
                            ->options(TicketChannel::class)
                            ->required(),
                        Forms\Components\TextInput::make('subject')
                            ->label('נושא')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('טיפול')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(TicketStatus::class)
                            ->required(),
                        Forms\Components\Select::make('priority')
                            ->label('עדיפות')
                            ->options(TicketPriority::class)
                            ->required(),
                        Forms\Components\TextInput::make('assignee')
                            ->label('אחראי')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('external_thread_ref')
                            ->label('מזהה שיחה חיצוני')
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('זמנים')
                    ->schema([
                        Forms\Components\DateTimePicker::make('first_response_at')
                            ->label('מענה ראשון'),
                        Forms\Components\DateTimePicker::make('resolved_at')
                            ->label('טופל בתאריך'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('מס׳')
                    ->prefix('#')
                    ->sortable()
                    ->searchable(isIndividual: true),
                Tables\Columns\TextColumn::make('subject')
                    ->label('נושא')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('מאת')
                    // Fall back to the captured sender identity (name + email /
                    // pushname + phone) for an unidentified enquiry.
                    ->state(fn (Ticket $record): string => $record->senderName())
                    ->description(fn (Ticket $record): ?string => $record->customer_id === null ? 'לא מזוהה' : null)
                    ->searchable(query: fn ($query, string $search) => $query->where(
                        fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                            ->orWhere('contact_name', 'like', "%{$search}%")
                            ->orWhere('contact_handle', 'like', "%{$search}%"),
                    )),
                Tables\Columns\TextColumn::make('channel')
                    ->label('ערוץ')
                    ->badge(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('עדיפות')
                    ->badge()
                    ->color(fn (TicketPriority $state): string => match ($state) {
                        TicketPriority::Low => 'gray',
                        TicketPriority::Normal => 'info',
                        TicketPriority::High => 'warning',
                        TicketPriority::Urgent => 'danger',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (TicketStatus $state): string => match ($state) {
                        TicketStatus::Open => 'warning',
                        TicketStatus::Pending => 'info',
                        TicketStatus::OnHold => 'gray',
                        TicketStatus::Resolved => 'success',
                        TicketStatus::Closed => 'gray',
                    }),
                Tables\Columns\TextColumn::make('assignee')
                    ->label('אחראי')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sla')
                    ->label('SLA תגובה')
                    ->badge()
                    ->getStateUsing(fn (Ticket $record): string => match ($record->firstResponseSlaStatus()) {
                        'met' => 'עמד ביעד',
                        'breached' => 'חריגה',
                        'at_risk' => 'בסיכון',
                        'ok' => 'בזמן',
                        default => '—',
                    })
                    ->color(fn (Ticket $record): string => match ($record->firstResponseSlaStatus()) {
                        'met', 'ok' => 'success',
                        'breached' => 'danger',
                        'at_risk' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Ticket $record): ?string => $record->firstResponseMinutes() !== null
                        ? 'תגובה ראשונה תוך '.$record->firstResponseMinutes().' דק׳'
                        : 'יעד תגובה: '.$record->firstResponseDueAt()->format('d/m/Y H:i'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נפתח')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(TicketStatus::class)
                    // Multi-select (choose several at once); defaults to the open
                    // queue. Clear the filter to see resolved/closed tickets too.
                    ->multiple()
                    ->default([TicketStatus::Open->value]),
                Tables\Filters\SelectFilter::make('priority')
                    ->label('עדיפות')
                    ->options(TicketPriority::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('channel')
                    ->label('ערוץ')
                    ->options(TicketChannel::class),
                Tables\Filters\Filter::make('sla_breached')
                    ->label('בחריגת SLA (ללא מענה)')
                    ->query(fn (Builder $query): Builder => $query->whereKey(self::breachedTicketIds())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('פתח שיחה')->icon('heroicon-o-chat-bubble-left-right'),
                // Quick "mark as resolved" straight from the list — the customer
                // gets the automatic "טופל" update on their channel (model event),
                // exactly like resolving from inside the conversation.
                Tables\Actions\Action::make('resolve')
                    ->label('סמן כטופל')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Ticket $record): bool => ! in_array($record->status, [TicketStatus::Resolved, TicketStatus::Closed], true))
                    ->requiresConfirmation()
                    ->modalHeading('סימון הפנייה כטופלה')
                    ->modalDescription('הלקוח יקבל אוטומטית עדכון שהטיפול הושלם, בערוץ שממנו פנה.')
                    ->action(fn (Ticket $record) => $record->update(['status' => TicketStatus::Resolved])),
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Close tickets in bulk without ever notifying the customer.
                    // Only "טופל" (Resolved) triggers the resolved notification;
                    // this sets "סגור" (Closed) via a query update, so no model
                    // event — and therefore no message — is fired.
                    Tables\Actions\BulkAction::make('closeSilently')
                        ->label('סגירה שקטה (ללא הודעה ללקוח)')
                        ->icon('heroicon-o-check-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('סגירת פניות ללא הודעה')
                        ->modalDescription('הפניות שנבחרו ייסגרו מבלי לשלוח שום הודעה ללקוח.')
                        ->successNotificationTitle('הפניות נסגרו')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => Ticket::whereKey($records->modelKeys())
                            ->update(['status' => TicketStatus::Closed->value, 'resolved_at' => now()])),
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין פניות עדיין');
    }

    public static function getRelations(): array
    {
        return [
            TicketResource\RelationManagers\MessagesRelationManager::class,
        ];
    }

    /**
     * Ids of open tickets that breached their first-response SLA. The per-priority
     * target lives in config, so the breach test runs in PHP over the open,
     * still-unanswered set (bounded — the open queue is small).
     *
     * @return array<int, int>
     */
    private static function breachedTicketIds(): array
    {
        return Ticket::query()
            ->where('status', TicketStatus::Open)
            ->whereNull('first_response_at')
            ->get(['id', 'priority', 'status', 'created_at'])
            ->filter(fn (Ticket $t): bool => $t->firstResponseSlaStatus() === 'breached')
            ->pluck('id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            // The default record page is the chat-style conversation view.
            'view' => Pages\ViewTicket::route('/{record}'),
            'edit' => Pages\EditTicket::route('/{record}/edit'),
        ];
    }
}
