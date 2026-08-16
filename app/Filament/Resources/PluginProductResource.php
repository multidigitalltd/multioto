<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PluginProductResource\Pages;
use App\Filament\Resources\PluginProductResource\RelationManagers\ReleasesRelationManager;
use App\Models\PluginProduct;
use App\Services\Licensing\GithubReleases;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

            Forms\Components\Section::make('מאיפה מגיעות הגרסאות')
                ->description('גרסה שמפורסמת כ-Release ב-GitHub נקלטת לכאן מעצמה, אחת לשעה. היא לא מופצת ללקוחות עד שלוחצים "הפץ גרסה זו" — פרסום גרסה לכל החנויות נשאר החלטה שמישהו מקבל.')
                ->schema([
                    Forms\Components\TextInput::make('github_repo')
                        ->label('מאגר GitHub')
                        ->placeholder('multidigitalltd/wc-store-enhancer')
                        ->rule('regex:/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/')
                        ->helperText('בפורמט owner/repo. השאירו ריק כדי להעלות קבצים ידנית.'),
                    Forms\Components\TextInput::make('github_token')
                        ->label('טוקן GitHub (למאגר פרטי)')
                        ->password()->revealable()
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('Fine-grained token עם הרשאת Contents: Read בלבד. נשמר מוצפן. למאגר ציבורי אין צורך.'),
                    Forms\Components\Toggle::make('pack_from_source')
                        ->label('אם אין קובץ מצורף — ארוז מקוד המקור')
                        // The default is off, and the reason is on screen: this
                        // is the difference between shipping a build and
                        // shipping a working directory.
                        ->helperText('כבוי = נדרש קובץ ZIP בנוי מצורף ל-Release (למשל מ-GitHub Action), וזו הדרך הנכונה. דלוק = ייארז כל מה שיש במאגר — כולל בדיקות וקובצי בנייה — וזה מה שיותקן אצל הלקוחות.')
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('github_state')
                        ->label('סנכרון אחרון')
                        ->columnSpanFull()
                        ->content(fn (?PluginProduct $record): string => match (true) {
                            $record === null || $record->github_synced_at === null => 'טרם סונכרן.',
                            filled($record->github_error) => 'נבדק '.$record->github_synced_at->diffForHumans().' — '.$record->github_error,
                            default => 'נבדק '.$record->github_synced_at->diffForHumans().'.',
                        }),
                ])->columns(2),

            Forms\Components\Section::make('מחיר')
                ->description('ברירות המחדל שמופיעות במסך המכירה. אפשר לשנות אותן בכל מכירה בנפרד.')
                ->schema([
                    Forms\Components\TextInput::make('price_agorot')
                        ->label('מחיר')
                        ->numeric()->minValue(0)
                        ->prefix('אגורות')
                        ->helperText('באגורות, לפני מע״מ. 10000 = ₪100.'),
                    Forms\Components\Select::make('billing_interval')
                        ->label('סוג הרישיון')
                        ->native(false)
                        ->options([
                            'yearly' => 'שנתי (מתחדש)',
                            'monthly' => 'חודשי (מתחדש)',
                        ])
                        ->placeholder('חד-פעמי (ללא חידוש)')
                        ->helperText('רישיון מתחדש פותח מנוי בעת המכירה — הגבייה, החשבונית והדאנינג הם אותם אלה של כל מנוי אחר.'),
                    Forms\Components\TextInput::make('default_sites_limit')
                        ->label('מספר אתרים כברירת מחדל')
                        ->numeric()->minValue(0)->default(1)
                        ->helperText('0 = ללא הגבלה.'),
                ])->columns(3),

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
                Tables\Actions\Action::make('sync')
                    ->label('סנכרון מ-GitHub')
                    ->icon('heroicon-o-arrow-down-on-square')
                    ->color('gray')
                    ->visible(fn (PluginProduct $record): bool => filled($record->github_repo))
                    ->action(function (PluginProduct $record): void {
                        $result = app(GithubReleases::class)->sync($record);

                        Notification::make()
                            ->title($record->name.' — סנכרון גרסאות')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->persistent()
                            ->send();
                    }),
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
