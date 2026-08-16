<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\LicenseResource\Pages;
use App\Filament\Resources\LicenseResource\RelationManagers\SitesRelationManager;
use App\Models\License;
use App\Models\PluginProduct;
use App\Services\Licensing\LicenseIssuer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The licences we sold: who may run which plugin, on how many shops, until
 * when.
 *
 * The key itself is nowhere on this screen, and cannot be — only its HMAC is
 * stored. It is shown once at issue and emailed to the customer; a lost key is
 * REPLACED, which invalidates the old one, and the button that does it says so
 * before it runs. That is a deliberate cost: a screen that could show a key is
 * a screen that leaks every key when somebody's session does.
 *
 * Expiry stops updates, never the plugin. A shop that did not renew keeps
 * trading; it just stops receiving new versions.
 */
class LicenseResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = License::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'רישיונות תוספים';

    protected static ?string $modelLabel = 'רישיון';

    protected static ?string $pluralModelLabel = 'רישיונות תוספים';

    protected static ?int $navigationSort = 61;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('הרישיון')
                ->schema([
                    Forms\Components\Select::make('plugin_product_id')
                        ->label('תוסף')
                        ->relationship('product', 'name')
                        ->required()
                        ->native(false)
                        ->disabled(fn (?License $record): bool => $record !== null)
                        ->dehydrated(fn (?License $record): bool => $record === null)
                        ->helperText(fn (?License $record): ?string => $record ? 'לא ניתן להעביר רישיון קיים לתוסף אחר — הנפיקו רישיון חדש.' : null),
                    Forms\Components\Select::make('customer_id')
                        ->label('לקוח')
                        ->relationship('customer', 'name')
                        ->searchable()->preload()->native(false)
                        ->helperText('אופציונלי — רישיון יכול להימכר גם למי שאינו לקוח במערכת.'),
                    Forms\Components\TextInput::make('email')
                        ->label('אימייל לשליחת המפתח')->email()->maxLength(150),
                    Forms\Components\TextInput::make('sites_limit')
                        ->label('מספר אתרים')
                        ->numeric()->minValue(0)->default(1)->required()
                        ->helperText('0 = ללא הגבלה.'),
                    Forms\Components\DatePicker::make('expires_at')
                        ->label('בתוקף עד')
                        ->helperText('ריק = ללא תפוגה. פקיעה עוצרת עדכונים בלבד — התוסף ממשיך לעבוד באתר.'),
                    Forms\Components\Select::make('subscription_id')
                        ->label('מנוי שמחדש אוטומטית')
                        ->relationship('subscription', 'name')
                        ->searchable()->preload()->native(false)
                        ->helperText('כשחיוב של המנוי הזה מצליח, התוקף נדחה לסוף התקופה ששולמה. בלי מנוי — החידוש ידני.'),
                ])->columns(2),

            Forms\Components\Section::make('הערות')
                ->schema([Forms\Components\Textarea::make('notes')->label('הערות פנימיות')->rows(2)])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key_prefix')
                    ->label('מפתח')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (?string $state): string => $state.'-••••-••••-••••')
                    ->description('המפתח המלא אינו נשמר'),
                Tables\Columns\TextColumn::make('product.name')->label('תוסף')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('status_label')
                    ->label('מצב')
                    ->badge()
                    ->state(fn (License $record): string => $record->statusLabel())
                    ->color(fn (License $record): string => $record->statusColor()),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('בתוקף עד')->date('d/m/Y')->sortable()
                    ->placeholder('ללא תפוגה')
                    ->description(fn (License $record): ?string => $record->subscription_id ? 'מתחדש אוטומטית' : null),
                Tables\Columns\TextColumn::make('seats')
                    ->label('אתרים')
                    ->state(fn (License $record): string => $record->isUnlimited()
                        ? $record->seatsUsed().' (ללא הגבלה)'
                        : $record->seatsUsed().' מתוך '.$record->sites_limit),
                // The single most useful column on this screen: a licence whose
                // shops stopped checking in is either uninstalled or broken, and
                // both are worth knowing before the renewal conversation.
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('בדיקה אחרונה')->dateTime('d/m/Y H:i')->since()
                    ->placeholder('מעולם')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('plugin_product_id')
                    ->label('תוסף')
                    ->options(fn (): array => PluginProduct::query()->pluck('name', 'id')->all()),
                Tables\Filters\Filter::make('expired')
                    ->label('פג תוקף')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('expires_at')->whereDate('expires_at', '<', now())),
                Tables\Filters\Filter::make('silent')
                    ->label('לא נבדק מעל 30 יום')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->whereNull('last_checked_at')
                        ->orWhere('last_checked_at', '<', now()->subDays(30)))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\Action::make('resendKey')
                    ->label('מפתח חדש ושליחה ללקוח')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('הנפקת מפתח חדש')
                    // The cost, before the click: this is not "resend", it is
                    // "replace". Anyone who reads it as the former will break a
                    // working installation and not know why.
                    ->modalDescription('המפתח הקיים יפסיק לעבוד מיד, והלקוח יצטרך להזין את החדש בכל אתר. המפתח הישן אינו ניתן לשחזור — הוא לא נשמר אצלנו מעולם. השתמשו בזה רק כשהמפתח אבד או דלף.')
                    ->modalSubmitActionLabel('הנפק מפתח חדש')
                    ->action(function (License $record): void {
                        $sent = app(LicenseIssuer::class)->reissue($record);

                        Notification::make()
                            ->title('הונפק מפתח חדש')
                            ->body($sent
                                ? 'המפתח נשלח לכתובת '.$record->email.'. המפתח הקודם בוטל.'
                                : 'המפתח הקודם בוטל, אך אין כתובת אימייל על הרישיון — הוסיפו כתובת ושלחו שוב.')
                            ->{$sent ? 'success' : 'warning'}()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\Action::make('revoke')
                    ->label(fn (License $record): string => $record->isRevoked() ? 'ביטול הביטול' : 'ביטול רישיון')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (License $record): string => $record->isRevoked() ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (License $record): string => $record->isRevoked() ? 'החזרת הרישיון לתוקף' : 'ביטול הרישיון')
                    ->modalDescription(fn (License $record): string => $record->isRevoked()
                        ? 'הרישיון יחזור לפעול והאתרים הרשומים עליו יקבלו שוב עדכונים.'
                        : 'האתרים יפסיקו לקבל עדכונים והמפתח יידחה. התוסף עצמו ימשיך לעבוד בכל אתר — ביטול רישיון אינו כיבוי של חנות.')
                    ->action(fn (License $record) => $record->update([
                        'status' => $record->isRevoked() ? License::ACTIVE : License::REVOKED,
                    ])),
            ])
            ->emptyStateHeading('אין רישיונות')
            ->emptyStateDescription('הנפיקו רישיון כדי שלקוח יוכל להפעיל את התוסף ולקבל עדכונים.');
    }

    public static function getRelations(): array
    {
        return [SitesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenses::route('/'),
            'edit' => Pages\EditLicense::route('/{record}/edit'),
        ];
    }
}
