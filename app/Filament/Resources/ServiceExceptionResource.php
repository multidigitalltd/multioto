<?php

namespace App\Filament\Resources;

use App\Enums\ServiceMode;
use App\Filament\Resources\ServiceExceptionResource\Pages;
use App\Models\ServiceException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Mark days the team works in a reduced capacity or handles urgent matters only.
 * The agent reads the active day and sets the right expectation on a new
 * ticket's acknowledgement (possible delay / urgent-only).
 */
class ServiceExceptionResource extends Resource
{
    protected static ?string $model = ServiceException::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'ימי שירות מיוחדים';

    protected static ?string $modelLabel = 'יום שירות מיוחד';

    protected static ?string $pluralModelLabel = 'ימי שירות מיוחדים';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('starts_on')
                ->label('מתאריך')->required()->native(false)->default(now())
                ->live()->afterStateUpdated(fn ($state, Forms\Set $set, Forms\Get $get) => filled($state) && blank($get('ends_on')) ? $set('ends_on', $state) : null),
            Forms\Components\DatePicker::make('ends_on')
                ->label('עד תאריך (כולל)')->required()->native(false)->default(now())
                ->afterOrEqual('starts_on'),
            Forms\Components\Select::make('mode')
                ->label('מצב')->options(ServiceMode::class)->required()->native(false)
                ->default(ServiceMode::Reduced),
            Forms\Components\TextInput::make('note')
                ->label('הערה (אופציונלי)')->maxLength(255)
                ->helperText('הערה פנימית שתעזור לסוכן לנסח — לא נשלחת כלשונה ללקוח.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('starts_on')->label('מתאריך')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('ends_on')->label('עד')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('mode')->label('מצב')->badge(),
                Tables\Columns\TextColumn::make('note')->label('הערה')->wrap()->limit(60)->toggleable(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->emptyStateHeading('אין ימים מסומנים')
            ->emptyStateDescription('סמנו ימים של מתכונת מצומצמת או דחוף-בלבד — הסוכן יעדכן את הלקוחות בהתאם.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServiceExceptions::route('/'),
        ];
    }
}
