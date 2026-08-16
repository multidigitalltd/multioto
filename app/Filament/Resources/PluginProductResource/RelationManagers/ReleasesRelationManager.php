<?php

namespace App\Filament\Resources\PluginProductResource\RelationManagers;

use App\Models\PluginRelease;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The builds of one plugin, and which of them customers are being given.
 *
 * Uploading a build and shipping it are two separate decisions here, on
 * purpose. Publishing means every shop on a valid licence is offered this zip
 * within six hours — that is not a thing to do by dragging a file into a form.
 */
class ReleasesRelationManager extends RelationManager
{
    protected static string $relationship = 'releases';

    protected static ?string $title = 'גרסאות';

    protected static ?string $modelLabel = 'גרסה';

    protected static ?string $pluralModelLabel = 'גרסאות';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('version')
                ->label('מספר גרסה')
                ->required()
                ->rule('regex:/^v?\d+(\.\d+){1,3}$/')
                ->helperText('לדוגמה 1.23.0. וורדפרס משווה מספרים — גרסה נמוכה מהמותקנת לא תוצע כעדכון.'),
            Forms\Components\FileUpload::make('zip_path')
                ->label('קובץ ZIP')
                ->required()
                ->disk((string) config('licensing.disk'))
                ->directory(trim((string) config('licensing.path'), '/'))
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                ->maxSize(51200)
                // Private disk: the zip is what customers pay for, and a
                // publicly reachable copy would make the licence decorative.
                ->visibility('private')
                ->helperText('הקובץ נשמר בדיסק פרטי. ההורדה מתאפשרת רק דרך קישור חתום שנקשר לרישיון ולאתר.'),
            Forms\Components\Textarea::make('changelog')
                ->label('מה השתנה')
                ->rows(4)
                ->columnSpanFull()
                ->helperText('HTML בסיסי (למשל <ul><li>…</li></ul>). מוצג ללקוח בחלון "הצג פרטים" של וורדפרס.'),
            Forms\Components\Toggle::make('is_current')
                ->label('זו הגרסה שמופצת ללקוחות')
                ->helperText('סימון כאן מוריד את הסימון מהגרסה הקודמת — לתוסף יש תמיד תשובה אחת לשאלה "מה להוריד".')
                ->default(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->columns([
                Tables\Columns\TextColumn::make('version')->label('גרסה')->weight('bold')->sortable(),
                Tables\Columns\IconColumn::make('is_current')->label('מופצת')->boolean(),
                Tables\Columns\TextColumn::make('released_at')->label('פורסמה')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('הועלתה')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('גרסה חדשה')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['released_at'] = ($data['is_current'] ?? false) ? now() : null;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('publish')
                    ->label('הפץ גרסה זו')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (PluginRelease $record): bool => ! $record->is_current)
                    ->requiresConfirmation()
                    ->modalHeading(fn (PluginRelease $record): string => 'הפצת גרסה '.$record->number())
                    ->modalDescription('כל אתר עם רישיון בתוקף יראה עדכון זמין תוך שש שעות לכל היותר, ויוכל להתקין אותו. הגרסה שמופצת כרגע תפסיק להיות מוצעת.')
                    ->modalSubmitActionLabel('הפץ ללקוחות')
                    ->action(function (PluginRelease $record): void {
                        $record->update(['is_current' => true, 'released_at' => $record->released_at ?? now()]);

                        Notification::make()
                            ->title('גרסה '.$record->number().' מופצת')
                            ->body('אתרים עם רישיון בתוקף יראו את העדכון תוך שש שעות, או מיד אחרי "בדוק עדכונים" בוורדפרס.')
                            ->success()->send();
                    }),
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\DeleteAction::make()
                    ->label('מחיקה')
                    // The distributed build is not deletable from here: removing
                    // it would leave the product with no answer to "what do I
                    // download" while shops keep asking every six hours.
                    ->visible(fn (PluginRelease $record): bool => ! $record->is_current),
            ])
            ->emptyStateHeading('אין גרסאות')
            ->emptyStateDescription('העלו קובץ ZIP וסמנו אותו כמופץ כדי שלקוחות יקבלו עדכון.');
    }
}
