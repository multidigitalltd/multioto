<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\SiteAgentSubscriberResource\Pages;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

/**
 * מי מנהל אתר מהוואטסאפ — the list of numbers the product will answer.
 *
 * Every row is permission to rewrite somebody's website from a phone, so the
 * screen is built to make that visible rather than tidy: it says whether the
 * number was proved, whether the customer is actually paying, and lets access
 * be taken away without deleting what that number already did.
 */
class SiteAgentSubscriberResource extends Resource
{
    use RespectsModuleAccess;

    protected static ?string $model = SiteAgentSubscriber::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'סוכן וואטסאפ לאתר';

    protected static ?string $modelLabel = 'מנוי סוכן';

    protected static ?string $pluralModelLabel = 'מנויי סוכן האתר';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('מי מנהל, ואיזה אתר')
                ->description('המספר יקבל קוד אימות בוואטסאפ, וישלח אותו חזרה כדי להוכיח שהוא מחזיק בו. עד אז הוא אינו יכול לעשות דבר.')
                ->schema([
                    Forms\Components\Select::make('site_id')
                        ->label('אתר')
                        ->options(fn (): array => Site::query()
                            ->where('mcp_enabled', true)
                            ->whereNotNull('mcp_endpoint')
                            ->with('customer')
                            ->get()
                            ->mapWithKeys(fn (Site $site): array => [
                                $site->id => $site->domain.' — '.($site->customer?->name ?? 'ללא לקוח'),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('רק אתרים מחוברים לתוסף. בלי חיבור אין לסוכן ידיים.')
                        // The customer is the site's, never chosen separately:
                        // two dropdowns would let somebody bind a number to
                        // customer A and site B, and the entitlement check would
                        // then read one customer's subscription for another
                        // customer's site.
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set, $state) => $set(
                            'customer_id',
                            Site::find($state)?->customer_id,
                        )),
                    Forms\Components\Hidden::make('customer_id')->required(),
                    Forms\Components\TextInput::make('phone')
                        ->label('מספר וואטסאפ')
                        ->tel()
                        ->required()
                        ->helperText('אפשר להקליד 050-1234567 — יומר לפורמט הבינלאומי אוטומטית.')
                        ->dehydrateStateUsing(fn (?string $state): string => app(WhatsAppCloudClient::class)->normalize((string) $state)),
                    Forms\Components\TextInput::make('name')
                        ->label('שם')
                        ->maxLength(120)
                        ->helperText('אופציונלי — יתמלא מהפרופיל בוואטסאפ אם ריק.'),
                ])->columns(2),
        ]);
    }

    /**
     * Whether the agent would answer this number, in one word.
     *
     * The subscription part comes from a column computed once for the whole
     * page (see getEloquentQuery), so a screen of fifty rows is one query and
     * not a hundred.
     */
    private static function stateOf(SiteAgentSubscriber $record): string
    {
        return match (true) {
            $record->revoked_at !== null => 'revoked',
            $record->verified_at === null => 'unverified',
            ! (bool) $record->customer_subscribed => 'unsubscribed',
            default => 'active',
        };
    }

    /**
     * Everything a row needs, fetched with the page.
     *
     * `customer_subscribed` is an exists-subquery rather than a method call per
     * row: the question "does this customer hold a live plan that includes the
     * agent" is asked for every line, and asking it row by row is the shape
     * that makes a list screen slow as the product grows.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer:id,name', 'site:id,domain,customer_id'])
            ->withExists(['customer as customer_subscribed' => fn (Builder $query) => $query
                ->whereHas('subscriptions', fn (Builder $subscription) => $subscription
                    ->whereIn('status', SiteAgentAccess::ENTITLING)
                    ->whereHas('plan', fn (Builder $plan) => $plan->where('includes_site_agent', true)))]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('phone')->label('מספר')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('שם')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('אתר')
                    ->searchable()
                    ->description(fn (SiteAgentSubscriber $record): ?string => $record->customer?->name),
                // The state that decides whether the agent answers this number,
                // said in one badge rather than inferred from three columns.
                Tables\Columns\TextColumn::make('state')
                    ->label('מצב')
                    ->badge()
                    // Read from the eager-loaded flag, not by asking the
                    // database per row. Both the label and the colour need the
                    // answer, so the naive version was two subscription queries
                    // for every line on the screen.
                    ->state(fn (SiteAgentSubscriber $record): string => match (self::stateOf($record)) {
                        'revoked' => 'ההרשאה הוסרה',
                        'unverified' => 'ממתין לאימות',
                        'unsubscribed' => 'אין מנוי פעיל',
                        default => 'פעיל',
                    })
                    ->color(fn (SiteAgentSubscriber $record): string => match (self::stateOf($record)) {
                        'revoked' => 'gray',
                        'unverified' => 'warning',
                        'unsubscribed' => 'danger',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('פעילות אחרונה')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('מעולם לא')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('sendCode')
                    ->label('שלח קוד אימות')
                    ->icon('heroicon-o-key')
                    ->visible(fn (SiteAgentSubscriber $record): bool => $record->verified_at === null && $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('שליחת קוד אימות')
                    ->modalDescription(fn (SiteAgentSubscriber $record): string => "יישלח קוד בן 6 ספרות למספר {$record->phone}. משליחתו הוא תקף ל-".(int) config('siteagent.binding.verification_ttl_minutes', 30).' דקות.')
                    ->action(function (SiteAgentSubscriber $record, WhatsAppCloudClient $whatsapp): void {
                        // Minted here and never shown on screen: a code the team
                        // can read is one the team can use, and the point of it
                        // is to prove the PHONE holder is who we think.
                        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                        $sent = $whatsapp->sendText($record->phone, implode("\n", [
                            'קוד האימות שלכם לניהול האתר '.$record->site?->domain.':',
                            $code,
                            '',
                            'שלחו אותו חזרה כאן כדי להתחיל.',
                        ]));

                        if ($sent === null) {
                            // Stamping a code we could not deliver would leave
                            // the team waiting for a reply to a message nobody
                            // received.
                            Notification::make()->title('הקוד לא נשלח')
                                ->body('לא הצלחנו לשלוח לוואטסאפ. בדקו את הגדרות החיבור ונסו שוב.')
                                ->danger()->send();

                            return;
                        }

                        $record->forceFill([
                            'verification_code' => Hash::make($code),
                            'verification_sent_at' => now(),
                            // A fresh code is a fresh chance: the counter that
                            // bounds guessing must not carry over, or resending
                            // to a customer who mistyped would hand them a code
                            // that is already spent.
                            'verification_attempts' => 0,
                        ])->save();

                        Notification::make()->title('הקוד נשלח')
                            ->body('הלקוח צריך לשלוח אותו חזרה באותה שיחה.')->success()->send();
                    }),

                Tables\Actions\Action::make('revoke')
                    ->label('הסר הרשאה')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (SiteAgentSubscriber $record): bool => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('הסרת ההרשאה מהמספר')
                    ->modalDescription('המספר יפסיק לנהל את האתר מיד. המנוי של הלקוח אינו משתנה, ומספרים אחרים שלו ממשיכים לעבוד.')
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('סיבה')
                            ->required()
                            ->maxLength(255)
                            ->helperText('נשמר לצד הרשומה — ביום שישאלו למה המספר הפסיק לעבוד, זו התשובה.'),
                    ])
                    ->action(function (SiteAgentSubscriber $record, array $data): void {
                        $record->forceFill([
                            'revoked_at' => now(),
                            'revoked_reason' => $data['reason'],
                            'verification_code' => null,
                        ])->save();

                        Notification::make()->title('ההרשאה הוסרה')->success()->send();
                    }),

                Tables\Actions\Action::make('restore')
                    ->label('החזר הרשאה')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (SiteAgentSubscriber $record): bool => $record->revoked_at !== null)
                    ->requiresConfirmation()
                    ->action(function (SiteAgentSubscriber $record): void {
                        $record->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();

                        Notification::make()->title('ההרשאה הוחזרה')->success()->send();
                    }),

                Tables\Actions\EditAction::make()->label('עריכה'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteAgentSubscribers::route('/'),
            'create' => Pages\CreateSiteAgentSubscriber::route('/create'),
            'edit' => Pages\EditSiteAgentSubscriber::route('/{record}/edit'),
        ];
    }
}
