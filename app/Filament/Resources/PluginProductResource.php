<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PluginProductResource\Pages;
use App\Filament\Resources\PluginProductResource\RelationManagers\ReleasesRelationManager;
use App\Models\PluginProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The plugins we sell, and the build each of them currently ships.
 *
 * The slug is the contract: it is how an installed copy names itself when it
 * asks us about updates, so once a single shop has it, changing it silently
 * cuts every one of them off. The form says so and the field locks after the
 * first save.
 */
class PluginProductResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = PluginProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'תוספים שאנחנו מוכרים';

    protected static ?string $modelLabel = 'תוסף';

    protected static ?string $pluralModelLabel = 'תוספים שאנחנו מוכרים';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('התוסף')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('שם')->required()->maxLength(120),
                    Forms\Components\TextInput::make('slug')
                        ->label('מזהה (slug)')
                        ->required()
                        ->maxLength(80)
                        ->rule('regex:/^[a-z0-9][a-z0-9\-]*$/')
                        // Locked after creation: this is the name every installed
                        // copy asks about updates by, and changing it cuts all of
                        // them off without a single error message anywhere.
                        ->disabled(fn (?PluginProduct $record): bool => $record !== null)
                        ->dehydrated(fn (?PluginProduct $record): bool => $record === null)
                        ->unique(ignoreRecord: true)
                        ->helperText('אותיות קטנות ומקפים. זהו השם שהתוסף המותקן מזדהה בו — לא ניתן לשנות אחרי היצירה.'),
                    Forms\Components\TextInput::make('homepage')->label('עמוד המוצר')->url()->maxLength(255),
                    Forms\Components\Textarea::make('description')->label('תיאור')->rows(2)->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')->label('פעיל למכירה')->default(true),
                ])->columns(2),

            Forms\Components\Section::make('תאימות שמדווחת לוורדפרס')
                ->description('מה שיוצג בעמוד התוספים באתר הלקוח. ריק = ברירת המחדל של המערכת.')
                ->schema([
                    Forms\Components\TextInput::make('requires')->label('גרסת וורדפרס מינימלית')
                        ->placeholder(config('licensing.defaults.requires')),
                    Forms\Components\TextInput::make('requires_php')->label('גרסת PHP מינימלית')
                        ->placeholder(config('licensing.defaults.requires_php')),
                    Forms\Components\TextInput::make('tested')->label('נבדק עד גרסת וורדפרס')
                        ->placeholder(config('licensing.defaults.tested')),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('תוסף')->searchable()->weight('bold')
                    ->description(fn (PluginProduct $record): string => $record->slug),
                // The distributed build, by name. A product with none is not
                // broken — it simply has nothing to hand out yet, and saying
                // "אין גרסה" is the difference between that and a fault.
                Tables\Columns\TextColumn::make('current')
                    ->label('גרסה מופצת')
                    ->badge()
                    ->state(fn (PluginProduct $record): string => $record->currentRelease()?->number() ?? 'אין גרסה')
                    ->color(fn (PluginProduct $record): string => $record->currentRelease() === null ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('licenses_count')->label('רישיונות')->counts('licenses')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('פעיל')->boolean(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->emptyStateHeading('אין עדיין תוספים')
            ->emptyStateDescription('הוסיפו תוסף כדי להנפיק עליו רישיונות ולהפיץ ממנו עדכונים.');
    }

    public static function getRelations(): array
    {
        return [ReleasesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPluginProducts::route('/'),
            'edit' => Pages\EditPluginProduct::route('/{record}/edit'),
        ];
    }
}
