<?php

namespace App\Filament\Resources;

use App\Enums\BillingInterval;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\PlanResource\Pages;
use App\Filament\Support\MoneyField;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    use RespectsModuleAccess;

    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'תוכניות';

    protected static ?string $modelLabel = 'תוכנית';

    protected static ?string $pluralModelLabel = 'תוכניות';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('פרטי התוכנית')
                    ->description('שם, מחיר ותנאי חיוב')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('שם התוכנית')
                            ->required()
                            ->maxLength(255),
                        MoneyField::make('price_agorot', 'מחיר (₪ לחודש)')
                            ->required(),
                        Forms\Components\Select::make('billing_interval')
                            ->label('תדירות חיוב')
                            ->options(BillingInterval::class)
                            ->required(),
                        Forms\Components\Toggle::make('vat_applies')
                            ->label('חל מע״מ')
                            ->inline(false)
                            ->required(),
                        Forms\Components\Toggle::make('active')
                            ->label('פעילה')
                            ->inline(false)
                            ->required(),
                        // What decides, on every message a customer sends to
                        // the agent's number, whether it answers them. A flag
                        // rather than a name match: renaming a plan, or opening
                        // a second one at another price, must never quietly
                        // switch a paying customer off.
                        Forms\Components\Toggle::make('includes_site_agent')
                            ->label('כולל בוט ניהול אתר בוואטסאפ')
                            ->helperText('לקוח עם מנוי פעיל בתוכנית הזו יכול לנהל את האתר שלו מהבוט. בלי זה — הבוט משיב שהמנוי אינו פעיל.')
                            ->inline(false)
                            ->live(),

                        // The two fields below decide whether the public
                        // storefront has anything to sell. Without them on this
                        // form the only way to publish a plan would be editing
                        // the database by hand — which is the same as the page
                        // not existing.
                        Forms\Components\Toggle::make('is_public')
                            ->label('מוצגת בעמוד הרכישה הציבורי')
                            ->helperText('רק תוכניות שמסומנות כאן נמכרות ב-/site-agent. השאירו כבוי לתוכנית במחיר שסוכם עם לקוח מסוים — אחרת המחיר מתפרסם, וכל אחד יכול לקנות בו.')
                            ->inline(false)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),

                        Forms\Components\TextInput::make('message_price_agorot')
                            ->label('מחיר להודעה שהבוט שולח (אגורות, לפני מע"מ)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('נגבה בחידוש החודשי על ההודעות שנשלחו מאז החיוב הקודם, בשורה נפרדת בחשבונית. ריק או 0 = הודעות אינן מחויבות. קודי אימות והודעות מערכת אינם נספרים.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),

                        Forms\Components\TextInput::make('included_messages')
                            ->label('הודעות כלולות במנוי (לכל מחזור חיוב)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('כמה הודעות בכל מחזור אינן מחויבות. רק ההודעות שמעבר להן מחויבות במחיר להודעה. 0 = כל הודעה מחויבת.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),

                        Forms\Components\TextInput::make('writing_price_agorot')
                            ->label('מחיר ליחידת כתיבה (אגורות, לפני מע"מ)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('יחידת כתיבה = טקסט של יותר מ-'.(int) config('siteagent.writing.min_words', 300).' מילים שה-AI שלנו כתב (פוסט, קטע בעמוד, תיאור מוצר). נספרת כשהטקסט נכתב — גם אם הלקוח לא אישר, וכל גרסה בנפרד. טקסט שהלקוח כתב בעצמו לא נספר. נגבה בחידוש, בשורה נפרדת. ריק או 0 = ללא חיוב.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),

                        Forms\Components\TextInput::make('included_writings')
                            ->label('יחידות כתיבה כלולות במנוי (לכל מחזור)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),

                        Forms\Components\TextInput::make('trial_days')
                            ->label('ימי ניסיון בחינם')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(60)
                            ->default(0)
                            ->helperText('ברכישה עצמית: הלקוח מזין כרטיס בלי חיוב, והחיוב הראשון יוצא בסוף הניסיון אלא אם ביטל. 0 = ללא ניסיון.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),

                        Forms\Components\TextInput::make('extra_number_price_agorot')
                            ->label('מחיר מספר מנהל נוסף (אגורות)')
                            ->numeric()
                            ->minValue(0)
                            // Left empty the plan simply does not sell extra
                            // numbers, which is not the same as giving them
                            // away: an empty field must not put a
                            // "הוסיפו מספר — ₪0" button on a customer's screen.
                            ->helperText('לאותו אתר, לכל מחזור חיוב. ריק = התוכנית אינה מאפשרת מספרים נוספים כלל. 0 = מספרים נוספים כלולים ללא תשלום.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('includes_site_agent')),
                        Forms\Components\Textarea::make('description')
                            ->label('תיאור')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('שם התוכנית')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('price_agorot')
                    ->label('מחיר')
                    ->money('ILS', divideBy: 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_interval')
                    ->label('תדירות חיוב')
                    ->badge(),
                Tables\Columns\IconColumn::make('vat_applies')
                    ->label('חל מע״מ')
                    ->boolean(),
                Tables\Columns\IconColumn::make('active')
                    ->label('פעילה')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('billing_interval')
                    ->label('תדירות חיוב')
                    ->options(BillingInterval::class),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('פעילה'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין תוכניות עדיין')
            ->emptyStateDescription('הקימו תוכנית חדשה דרך "תוכנית חדשה" בתפריט.');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
