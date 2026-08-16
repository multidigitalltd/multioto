<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use App\Models\LicenseSite;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The shops running this licence.
 *
 * Rows appear by themselves — a shop activating the plugin registers itself —
 * so there is no "add" here. What there is, is the ability to take one off: "I
 * changed domain" and "that site is closed" are the two support requests a
 * licence server generates, and both are one click from this list.
 *
 * "Last seen" is the column that earns this screen. A shop that stopped
 * checking in either uninstalled the plugin or is broken, and both are worth
 * knowing before the renewal conversation rather than after it.
 */
class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = 'אתרים רשומים';

    protected static ?string $modelLabel = 'אתר';

    protected static ?string $pluralModelLabel = 'אתרים';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('site_url')
            ->columns([
                Tables\Columns\TextColumn::make('site_url')->label('אתר')->weight('medium')
                    ->description(fn (LicenseSite $record): ?string => $record->reported_url !== $record->site_url
                        ? 'דווח כ-'.$record->reported_url
                        : null),
                Tables\Columns\TextColumn::make('version')->label('גרסה מותקנת')->placeholder('לא ידוע')->badge(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('נראה לאחרונה')->dateTime('d/m/Y H:i')->since()
                    ->placeholder('מעולם')->sortable(),
                Tables\Columns\TextColumn::make('activated_at')->label('הופעל')->dateTime('d/m/Y')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('שחרור האתר')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalHeading('שחרור האתר מהרישיון')
                    ->modalDescription('המקום יתפנה והלקוח יוכל להפעיל את הרישיון באתר אחר. האתר הזה יפסיק לקבל עדכונים; התוסף עצמו ימשיך לעבוד בו.')
                    ->modalSubmitActionLabel('שחרר')
                    ->after(fn () => Notification::make()->title('האתר שוחרר')->success()->send()),
            ])
            ->emptyStateHeading('אין אתרים רשומים')
            ->emptyStateDescription('אתר נרשם כאן ברגע שהלקוח מפעיל את הרישיון מתוך התוסף.');
    }
}
