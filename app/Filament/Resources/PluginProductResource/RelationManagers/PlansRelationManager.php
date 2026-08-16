<?php

namespace App\Filament\Resources\PluginProductResource\RelationManagers;

use App\Models\PluginPlan;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The ways one plugin is sold.
 *
 * A plugin is rarely one price. One site or five, monthly or yearly, and the
 * one that does not fit a price field at all — bought outright, no updates.
 * Each of those is a different product to the buyer, not a different number, so
 * each is a row here with its own name.
 *
 * The field that decides the most is "עדכונים": it is what the licence server
 * answers when the customer's shop asks whether there is a newer version.
 */
class PlansRelationManager extends RelationManager
{
    protected static string $relationship = 'plans';

    protected static ?string $title = 'מסלולי מכירה';

    protected static ?string $modelLabel = 'מסלול';

    protected static ?string $pluralModelLabel = 'מסלולים';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('שם המסלול')->required()->maxLength(80)
                ->placeholder('אתר בודד — שנתי')
                ->helperText('מה שהלקוח יראה על הכפתור.'),
            Forms\Components\TextInput::make('price_agorot')
                ->label('מחיר')->numeric()->minValue(0)->required()->prefix('אגורות')
                ->helperText('באגורות, לפני מע״מ. 20000 = ₪200. הלקוח יראה את המחיר כולל מע״מ.'),
            Forms\Components\TextInput::make('sites_limit')
                ->label('מספר אתרים')->numeric()->minValue(0)->default(1)->required()
                ->helperText('0 = ללא הגבלה.'),
            Forms\Components\Select::make('billing_interval')
                ->label('תשלום')
                ->native(false)->live()
                ->options(['yearly' => 'מנוי שנתי', 'monthly' => 'מנוי חודשי'])
                ->placeholder('תשלום חד-פעמי')
                ->helperText('מנוי = נפתחת גבייה מתחדשת, והעדכונים נמשכים כל עוד הוא משולם.'),
            Forms\Components\TextInput::make('updates_months')
                ->label('חודשי עדכונים כלולים')
                ->numeric()->minValue(0)->maxValue(240)
                // Only meaningful for a one-off: a subscription's updates last
                // exactly as long as it is paid for, and a second answer to the
                // same question is a second answer to get wrong.
                ->visible(fn (Forms\Get $get): bool => blank($get('billing_interval')))
                ->helperText('ריק או 0 = בלי עדכונים כלל: הלקוח מקבל את התוסף לתמיד בגרסה הנוכחית, והרישיון לא פג לעולם. 12 = שנה של עדכונים, ואחריה התוסף ממשיך לעבוד ומפסיק לקבל גרסאות.'),
            Forms\Components\Textarea::make('description')
                ->label('שורת הסבר (אופציונלי)')->rows(2)->maxLength(200)->columnSpanFull(),
            Forms\Components\TextInput::make('position')
                ->label('סדר הצגה')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')
                ->label('מוצג למכירה')->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('מסלול')->weight('bold'),
                Tables\Columns\TextColumn::make('price')
                    ->label('מחיר ללקוח')
                    ->state(fn (PluginPlan $record): string => $record->priceLabel())
                    ->description(fn (PluginPlan $record): string => 'לפני מע״מ '.Money::ils($record->price_agorot)),
                Tables\Columns\TextColumn::make('sites')
                    ->label('אתרים')
                    ->state(fn (PluginPlan $record): string => $record->sitesLabel()),
                // The column that says what the licence server will answer.
                Tables\Columns\TextColumn::make('updates')
                    ->label('עדכונים')
                    ->badge()
                    ->state(fn (PluginPlan $record): string => $record->updatesLabel())
                    ->color(fn (PluginPlan $record): string => $record->includesUpdates() ? 'success' : 'gray'),
                Tables\Columns\IconColumn::make('is_active')->label('מוצג')->boolean(),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('מסלול חדש'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\DeleteAction::make()
                    ->label('מחיקה')
                    // Deleting a plan does not touch a licence sold on it: what
                    // somebody bought is recorded on the licence itself, and a
                    // price list changing years later must not change it.
                    ->modalDescription('המסלול יוסר מעמוד המכירה. רישיונות שכבר נמכרו בו אינם משתנים — מה שנמכר רשום על הרישיון עצמו.'),
            ])
            ->emptyStateHeading('אין מסלולי מכירה')
            ->emptyStateDescription('בלי מסלול אחד לפחות אין מחיר, ועמוד המכירה של התוסף אינו נגיש.');
    }
}
