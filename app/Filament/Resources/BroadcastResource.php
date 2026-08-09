<?php

namespace App\Filament\Resources;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Enums\WebhookSource;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Pages\ManageMail;
use App\Filament\Resources\BroadcastResource\Actions\BroadcastSendActions;
use App\Filament\Resources\BroadcastResource\Pages;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Plan;
use App\Models\WebhookEvent;
use App\Services\Support\BroadcastAudience;
use App\Services\Support\BroadcastComposer;
use App\Services\Support\BroadcastRenderer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class BroadcastResource extends Resource
{
    use RespectsModuleAccess;

    protected static ?string $model = Broadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'דיוורים';

    protected static ?string $modelLabel = 'דיוור';

    protected static ?string $pluralModelLabel = 'דיוורים';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('תוכן הדיוור')
                    ->schema([
                        Forms\Components\Select::make('channel')
                            ->label('ערוץ')
                            ->options(BroadcastChannel::class)
                            ->default(BroadcastChannel::Email)
                            ->required()
                            ->live()
                            ->helperText(fn ($state): string => static::channelOf($state) === BroadcastChannel::Whatsapp
                                ? 'וואטסאפ נשלח לאט במכוון ('.config('billing.waha.broadcast_throttle_seconds').' שניות בין הודעה להודעה) כדי לא לסכן חסימה של המספר. לדיוור רחב עדיף אימייל.'
                                : 'האימייל נשלח בקבוצות דרך התור — מתאים לדיוור רחב.'),
                        Forms\Components\TextInput::make('subject')
                            ->label('נושא')
                            ->required()
                            ->maxLength(255)
                            ->helperText(fn (Forms\Get $get): ?string => static::channelOf($get('channel')) === BroadcastChannel::Whatsapp
                                ? 'בוואטסאפ הנושא משמש לזיהוי פנימי בלבד — הלקוח רואה רק את התוכן.'
                                : null),
                        Forms\Components\RichEditor::make('body')
                            ->label('תוכן')
                            ->required()
                            // Same toolbar as the ticket reply box, so writing to
                            // a customer feels the same wherever you do it.
                            ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                            ->helperText(new HtmlString(
                                'אפשר לשלב משתנים שיוחלפו לכל לקוח: '.collect(BroadcastRenderer::TOKENS)
                                    ->map(fn (string $what, string $token): string => '<code>{{'.e($token).'}}</code> — '.e($what))
                                    ->implode(' · ')
                                .'<br>בוואטסאפ העיצוב מומר אוטומטית לסימון של וואטסאפ (מודגש, רשימות).'
                            ))
                            ->columnSpanFull(),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('compose')
                                ->label('נסח לי עם הסוכן')
                                ->icon('heroicon-o-sparkles')
                                ->color('primary')
                                ->visible(fn (): bool => app(BroadcastComposer::class)->isAvailable())
                                ->modalHeading('ניסוח דיוור')
                                ->modalDescription('כתוב בשורה מה רוצים להגיד, והסוכן ינסח בשפת התמיכה שלנו. הנוסח ייכנס לטופס ותוכל לערוך אותו לפני שליחה.')
                                ->modalSubmitActionLabel('נסח')
                                ->form([
                                    Forms\Components\Textarea::make('brief')
                                        ->label('מה רוצים להגיד?')
                                        ->placeholder('לדוגמה: בשבת הקרובה בין 2 ל-5 לפנות בוקר נעשה תחזוקה בשרתים, ייתכנו כמה דקות של אי-זמינות')
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(1500),
                                ])
                                ->action(function (array $data, Forms\Set $set, Forms\Get $get) {
                                    $draft = app(BroadcastComposer::class)->draft(
                                        (string) $data['brief'],
                                        static::channelOf($get('channel')),
                                        (bool) $get('is_marketing'),
                                    );

                                    if ($draft === null) {
                                        Notification::make()->danger()
                                            ->title('הסוכן לא הצליח לנסח')
                                            ->body('נסו שוב עם תקציר מפורט יותר, או בדקו שחיבור ה-AI פעיל.')
                                            ->send();

                                        return;
                                    }

                                    $set('subject', $draft['subject']);
                                    $set('body', $draft['body']);

                                    Notification::make()->success()
                                        ->title('הנוסח מוכן')
                                        ->body('עברו עליו ותקנו מה שצריך — שום דבר לא נשלח עדיין.')
                                        ->send();
                                }),
                        ])->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('סוג ההודעה')
                    ->schema([
                        Forms\Components\Radio::make('is_marketing')
                            ->label('')
                            ->boolean('הודעה פרסומית', 'הודעת שירות')
                            ->default(true)
                            ->live()
                            ->descriptions([
                                1 => 'מבצע, שירות חדש, הצעה. לשורת הנושא תתווסף המילה "(פרסומת)", בתחתית ההודעה פרטי העסק וקישור הסרה, ולקוחות שביקשו להסיר אותם לא יקבלו — כנדרש בחוק התקשורת.',
                                0 => 'תחזוקה מתוכננת, עדכון אבטחה, שינוי בשירות. אינה פרסומת, ולכן נשלחת גם ללקוחות שהוסרו מרשימת הדיוור.',
                            ])
                            ->required(),
                        Forms\Components\Placeholder::make('sender_details')
                            ->label('פרטי השולח')
                            ->content(fn (): HtmlString => static::senderDetails()),
                    ])
                    ->hidden(fn (?Broadcast $record): bool => $record?->status === BroadcastStatus::Sent),

                Forms\Components\Section::make('קהל יעד')
                    ->description('השאירו הכל כברירת המחדל כדי לשלוח לכל הלקוחות הפעילים.')
                    ->schema([
                        Forms\Components\Select::make('segment.status')
                            ->label('סטטוס הלקוחות')
                            ->options(['all' => 'כל הלקוחות (כולל מושהים ונוטשים)'] + collect(CustomerStatus::cases())
                                ->mapWithKeys(fn (CustomerStatus $s) => [$s->value => $s->getLabel()])->all())
                            ->default(CustomerStatus::Active->value)
                            ->selectablePlaceholder(false)
                            ->live(),
                        Forms\Components\Select::make('segment.plan_ids')
                            ->label('רק לקוחות בחבילות')
                            ->multiple()
                            ->options(fn () => Plan::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('ריק = כל החבילות')
                            ->live(),
                        Forms\Components\Select::make('segment.customer_ids')
                            ->label('רק לקוחות מסוימים')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('ריק = כל מי שתואם לתנאים שלמעלה')
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('audience_summary')
                            ->label('כמה יקבלו')
                            ->content(fn (Forms\Get $get): HtmlString => static::audienceSummary($get))
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('תזמון')
                    ->description('אפשר להשאיר ריק ולשלוח ידנית בכפתור "שלח עכשיו".')
                    ->schema([
                        Forms\Components\DateTimePicker::make('scheduled_at')
                            ->label('שליחה אוטומטית בתאריך')
                            ->seconds(false)
                            ->after('now')
                            ->helperText('המערכת בודקת כל חמש דקות, כך שהשליחה תתחיל סמוך לשעה שנבחרה. בשבת ובחג השליחה נדחית אוטומטית למוצאי החג.'),
                    ])->columns(2)
                    // A sent broadcast is history: rescheduling it would either do
                    // nothing or invite a second send to the same people.
                    ->hidden(fn (?Broadcast $record): bool => $record?->status === BroadcastStatus::Sent),
            ])
            ->disabled(fn (?Broadcast $record): bool => $record !== null && in_array(
                $record->status, [BroadcastStatus::Sending, BroadcastStatus::Sent], true,
            ));
    }

    /**
     * Form state for an enum-backed select arrives as the enum on first render
     * (from the default or the cast model) and as its plain value after the
     * field is touched — comparing one shape only silently breaks the other.
     */
    protected static function channelOf(mixed $state): BroadcastChannel
    {
        return $state instanceof BroadcastChannel
            ? $state
            : (BroadcastChannel::tryFrom((string) $state) ?? BroadcastChannel::Email);
    }

    /**
     * Shows the operator exactly which sender details will appear at the bottom
     * of the message — and where they are edited. They come from the mail
     * settings ("שם שולח" and "כותרת תחתונה למיילים"), the same place every
     * other customer email takes them from, so there is one thing to keep
     * correct rather than a separate copy per feature.
     */
    protected static function senderDetails(): HtmlString
    {
        $name = e((string) (config('mail.from.name') ?: config('app.name')));
        $footer = trim((string) config('billing.branding.email_footer'));

        $link = '<a class="text-primary-600 underline" href="'.e(ManageMail::getUrl()).'">הגדרות ← מייל ושולח</a>';

        if ($footer === '') {
            return new HtmlString(
                '<span class="text-warning-600">בתחתית ההודעה יופיע "'.$name.'" בלבד — לא הוגדרה כותרת תחתונה עם כתובת וטלפון.</span><br>'
                .'<span class="text-sm text-gray-500">חוק התקשורת מחייב הודעת פרסומת לשאת את שם המפרסם וכתובתו. אפשר למלא ב-'.$link.'.</span>'
            );
        }

        return new HtmlString(
            '<span class="text-sm">בתחתית ההודעה יופיעו פרטי השולח מהגדרות הדיוור:</span><br>'
            .'<span class="text-sm text-gray-500" style="white-space:pre-line">'.e($footer).'</span><br>'
            .'<span class="text-sm text-gray-500">לעדכון: '.$link.'</span>'
        );
    }

    /**
     * The live "who will get this" line under the segment builder — the same
     * count the send confirmation shows, so nobody presses send without knowing
     * how many people are about to hear from them.
     */
    protected static function audienceSummary(Forms\Get $get): HtmlString
    {
        $channel = static::channelOf($get('channel'));

        $counts = app(BroadcastAudience::class)->summary($channel, [
            'status' => $get('segment.status'),
            'plan_ids' => $get('segment.plan_ids'),
            'customer_ids' => $get('segment.customer_ids'),
        ], marketing: (bool) $get('is_marketing'));

        if ($counts['reachable'] === 0) {
            $line = '<span class="text-danger-600 font-semibold">אף לקוח לא יקבל את הדיוור הזה.</span>';

            // The reason matters most exactly here: "nobody will get this" with
            // no explanation sends the operator hunting for a missing address
            // that is in fact filled in and dead.
            if (($counts['bounced'] ?? 0) > 0) {
                $line .= '<br><span class="text-sm text-gray-500">'.$counts['bounced'].' מהם עם כתובת שחזרה כלא קיימת — יש לעדכן כתובת אחרת.</span>';
            }

            if ($counts['opted_out'] > 0) {
                $line .= '<br><span class="text-sm text-gray-500">'.$counts['opted_out'].' מהם ביקשו להסיר אותם מדיוור פרסומי.</span>';
            }

            return new HtmlString($line);
        }

        $missing = $channel === BroadcastChannel::Email ? 'בלי כתובת אימייל' : 'בלי מספר וואטסאפ';

        $line = '<span class="font-semibold text-success-600">'.$counts['reachable'].' לקוחות יקבלו את הדיוור.</span>';

        if ($counts['unreachable'] > 0) {
            $line .= '<br><span class="text-sm text-gray-500">'.$counts['unreachable'].' לקוחות תואמים לקהל אך ידולגו — '.$missing.'.</span>';
        }

        if (($counts['bounced'] ?? 0) > 0) {
            $line .= '<br><span class="text-sm text-gray-500">'.$counts['bounced'].' לקוחות עם כתובת שחזרה כלא קיימת ולא יקבלו עד שתעודכן כתובת אחרת.</span>';
        }

        if ($counts['opted_out'] > 0) {
            $line .= '<br><span class="text-sm text-gray-500">'.$counts['opted_out'].' לקוחות ביקשו להסיר אותם מדיוור פרסומי ולא יקבלו.</span>';
        }

        return new HtmlString($line);
    }

    /**
     * Delivery and open counts for one broadcast, straight from the provider's
     * events. Shown as "—" until the first event lands, rather than as zeros —
     * "0 נמסרו" a second after sending reads like a failure when it only means
     * the provider has not reported back yet.
     */
    protected static function deliveryLabel(Broadcast $record): string
    {
        if ($record->channel !== BroadcastChannel::Email) {
            return '—'; // WhatsApp gives us no delivery signal at all.
        }

        $stats = NotificationLog::query()
            ->where('broadcast_id', $record->id)
            ->selectRaw('COUNT(*) AS handed_over')
            ->selectRaw('COUNT(delivered_at) AS delivered')
            ->selectRaw('COUNT(opened_at) AS opened')
            ->selectRaw('COUNT(bounced_at) AS bounced')
            ->first();

        // Nothing was ever handed to the provider — a draft, a scheduled send
        // that hasn't run, or a send that found nobody to send to. There is no
        // report to wait for and nothing to diagnose, so the column stays blank
        // rather than raising a question about an integration this row never
        // used.
        if ((int) ($stats?->handed_over ?? 0) === 0) {
            return '—';
        }

        // The placeholder hangs on whether the PROVIDER has said anything, not
        // on whether we queued anything: a log row exists the moment we hand the
        // mail over, so counting rows would flip this to "0 נמסרו" a second
        // after sending — which reads like a failed send rather than a pending one.
        $reported = (int) ($stats?->delivered ?? 0)
            + (int) ($stats?->opened ?? 0)
            + (int) ($stats?->bounced ?? 0);

        if ($reported === 0) {
            // "—" alone cannot tell "the provider hasn't reported yet" from
            // "nobody ever connected the provider" — and the second one stays
            // "—" forever while the team waits for numbers that can never
            // arrive. Say which it is.
            return static::deliveryTrackingConnected()
                ? '— ממתין לדיווח מהספק'
                : '— מעקב מסירה לא מוגדר';
        }

        $parts = [$stats->delivered.' נמסרו', $stats->opened.' נפתחו'];

        if ($stats->bounced > 0) {
            $parts[] = $stats->bounced.' חזרו';
        }

        return implode(' · ', $parts);
    }

    /**
     * Has the email provider EVER posted a delivery event to us?
     *
     * The counts come from the provider's webhook, so an install where that
     * webhook was never registered (or where open tracking was never switched
     * on at the provider) reports nothing at all — for every broadcast, forever.
     * One "did anything ever arrive" answer separates a young send from a
     * missing integration.
     *
     * Cached: this runs once per row on a list screen, and the answer flips at
     * most once in the life of an install.
     */
    protected static function deliveryTrackingConnected(): bool
    {
        return Cache::remember('broadcast-delivery-tracking-connected', now()->addMinutes(10), function (): bool {
            try {
                return WebhookEvent::query()
                    ->where('source', WebhookSource::Email)
                    ->where('event_type', 'like', 'delivery_%')
                    ->exists();
            } catch (\Throwable) {
                return true; // never let the log table turn into a false alarm
            }
        });
    }

    /** A one-line, human reading of a stored segment, for the list screen. */
    protected static function segmentLabel(Broadcast $record): string
    {
        $segment = $record->segment ?? [];
        $status = (string) ($segment['status'] ?? CustomerStatus::Active->value);

        $parts = [match (CustomerStatus::tryFrom($status)) {
            CustomerStatus::Active => 'לקוחות פעילים',
            CustomerStatus::Suspended => 'לקוחות מושהים',
            CustomerStatus::Churned => 'לקוחות שנטשו',
            default => 'כל הלקוחות',
        }];

        if (filled($segment['plan_ids'] ?? null)) {
            $parts[] = count($segment['plan_ids']).' חבילות נבחרות';
        }

        if (filled($segment['customer_ids'] ?? null)) {
            $parts[] = count($segment['customer_ids']).' לקוחות נבחרים';
        }

        return implode(' · ', $parts);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('נושא')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('channel')
                    ->label('ערוץ')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (BroadcastStatus $state): string => match ($state) {
                        BroadcastStatus::Draft => 'gray',
                        BroadcastStatus::Scheduled => 'info',
                        BroadcastStatus::Sending => 'warning',
                        BroadcastStatus::Sent => 'success',
                    }),
                Tables\Columns\TextColumn::make('audience')
                    ->label('קהל יעד')
                    ->state(fn (Broadcast $record): string => static::segmentLabel($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('מתוזמן')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sent_count')
                    ->label('נשלחו')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery')
                    ->label('נמסרו / נפתחו')
                    ->state(fn (Broadcast $record): string => static::deliveryLabel($record))
                    ->tooltip('מגיע מספק הדואר. פתיחות נמדדות בפיקסל ולכן הן רצפה, לא מספר מדויק — לקוח שחוסם תמונות נספר כמי שלא פתח.')
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(BroadcastStatus::class),
                Tables\Filters\SelectFilter::make('channel')
                    ->label('ערוץ')
                    ->options(BroadcastChannel::class),
            ])
            ->actions([
                Tables\Actions\Action::make('sendNow')
                    ->label('שלח עכשיו')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (Broadcast $record): bool => BroadcastSendActions::isSendable($record))
                    ->requiresConfirmation()
                    ->modalHeading('שליחת דיוור ללקוחות')
                    ->modalDescription(fn (Broadcast $record): HtmlString => BroadcastSendActions::confirmation($record))
                    ->modalSubmitActionLabel('שלח עכשיו')
                    ->action(fn (Broadcast $record) => BroadcastSendActions::send($record)->send()),
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('מחיקה')
                        // Deleting a row mid-send leaves the running job writing
                        // its progress and final status to a record that is gone,
                        // while the messages keep going out. The single-row delete
                        // already refuses this; the bulk path must match.
                        ->action(function (Collection $records, Tables\Actions\DeleteBulkAction $action) {
                            [$sending, $deletable] = $records->partition(
                                fn (Broadcast $record): bool => $record->status === BroadcastStatus::Sending,
                            );

                            $deletable->each->delete();

                            if ($sending->isNotEmpty()) {
                                Notification::make()->warning()
                                    ->title('חלק מהדיוורים לא נמחקו')
                                    ->body($sending->count().' דיוורים נמצאים כרגע בשליחה ואי אפשר למחוק אותם עד שתסתיים.')
                                    ->send();
                            }

                            $action->success();
                        }),
                ]),
            ])
            ->emptyStateHeading('אין דיוורים עדיין');
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
            'index' => Pages\ListBroadcasts::route('/'),
            'create' => Pages\CreateBroadcast::route('/create'),
            'edit' => Pages\EditBroadcast::route('/{record}/edit'),
        ];
    }
}
