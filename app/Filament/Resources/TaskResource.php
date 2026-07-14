<?php

namespace App\Filament\Resources;

use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Filament\Resources\TaskResource\Pages;
use App\Models\Task;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Internal team tasks — create, assign to a teammate, link to a customer and/or
 * the originating ticket, set a due date, and track through to done. Assignees
 * are reminded of due tasks in-panel (the "המשימות שלי" widget) and by email.
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'משימות';

    protected static ?string $modelLabel = 'משימה';

    protected static ?string $pluralModelLabel = 'משימות';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = Task::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('כותרת')->required()->maxLength(255)->columnSpanFull(),
                    Forms\Components\Textarea::make('description')
                        ->label('תיאור')->rows(3)->columnSpanFull(),
                    // A checklist of small items that get ticked off one by one.
                    Forms\Components\Repeater::make('subtasks')
                        ->label('תת-משימות')
                        ->schema([
                            Forms\Components\TextInput::make('title')->label('פריט')->required()->columnSpan(3),
                            Forms\Components\Toggle::make('done')->label('בוצע')->default(false)->inline(false),
                        ])
                        ->columns(4)
                        ->addActionLabel('הוסף תת-משימה')
                        ->reorderable()
                        ->collapsed(false)
                        ->default([])
                        ->columnSpanFull(),
                    Forms\Components\Select::make('assignees')
                        ->label('אחראים')->relationship('assignees', 'name')->multiple()->searchable()->preload()
                        ->placeholder('ללא שיוך'),
                    Forms\Components\Select::make('status')
                        ->label('סטטוס')->options(TaskStatus::class)->default(TaskStatus::Open)->required(),
                    Forms\Components\Select::make('priority')
                        ->label('עדיפות')->options(TicketPriority::class)->default(TicketPriority::Normal)->required(),
                    Forms\Components\DateTimePicker::make('due_at')
                        ->label('מועד יעד')->seconds(false)->native(false),
                    Forms\Components\Select::make('customer_id')
                        ->label('לקוח מקושר')->relationship('customer', 'name')->searchable()->preload()
                        ->placeholder('ללא'),
                    Forms\Components\Select::make('ticket_id')
                        ->label('פנייה מקושרת')->relationship('ticket', 'subject')->searchable()
                        ->placeholder('ללא'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignees', 'customer']))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('כותרת')->searchable()->wrap()->weight('medium'),
                Tables\Columns\TextColumn::make('assignees.name')->label('אחראים')->badge()->placeholder('ללא'),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('subtasks_progress')->label('תת-משימות')
                    ->getStateUsing(function (Task $record): string {
                        [$done, $total] = $record->subtaskProgress();

                        return $total > 0 ? "{$done}/{$total} ✓" : '—';
                    })->toggleable(),
                Tables\Columns\TextColumn::make('priority')->label('עדיפות')->badge(),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('due_at')->label('יעד')->dateTime('d/m/Y')->placeholder('—')->sortable()
                    ->color(fn (Task $record): ?string => $record->due_at && $record->status !== TaskStatus::Done
                        && $record->due_at->isPast() ? 'danger' : null),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                Tables\Filters\Filter::make('mine')
                    ->label('המשימות שלי')
                    ->query(fn (Builder $query): Builder => $query->whereHas('assignees', fn (Builder $q) => $q->whereKey(auth()->id()))),
                // Multi-select so the team can watch several statuses at once;
                // defaults to the live work list (open + in progress).
                Tables\Filters\SelectFilter::make('status')->label('סטטוס')->options(TaskStatus::class)
                    ->multiple()->default([TaskStatus::Open->value, TaskStatus::InProgress->value]),
                Tables\Filters\SelectFilter::make('priority')->label('עדיפות')->options(TicketPriority::class)->multiple(),
                Tables\Filters\Filter::make('overdue')
                    ->label('באיחור')
                    ->query(fn (Builder $query): Builder => $query->where('status', '!=', TaskStatus::Done)
                        ->whereNotNull('due_at')->where('due_at', '<', now())),
            ])
            ->actions([
                Tables\Actions\Action::make('complete')
                    ->label('סמן כהושלם')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Task $record): bool => $record->status !== TaskStatus::Done)
                    ->action(fn (Task $record) => $record->update(['status' => TaskStatus::Done])),
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\DeleteAction::make()->label('מחיקה'),
            ])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('אין משימות')
            ->emptyStateDescription('צרו משימה חדשה, או הפכו פנייה למשימה מתוך מסך הפנייה.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
