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
                Tables\Columns\TextColumn::make('subject')
                    ->label('נושא')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')
                    ->searchable()
                    ->sortable(),
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
                    ->options(TicketStatus::class),
                Tables\Filters\SelectFilter::make('priority')
                    ->label('עדיפות')
                    ->options(TicketPriority::class),
                Tables\Filters\SelectFilter::make('channel')
                    ->label('ערוץ')
                    ->options(TicketChannel::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            'edit' => Pages\EditTicket::route('/{record}/edit'),
        ];
    }
}
