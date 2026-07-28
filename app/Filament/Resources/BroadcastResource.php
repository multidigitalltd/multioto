<?php

namespace App\Filament\Resources;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\BroadcastResource\Actions\BroadcastSendActions;
use App\Filament\Resources\BroadcastResource\Pages;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\Support\BroadcastAudience;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
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
                            ->helperText(fn (?string $state): string => $state === BroadcastChannel::Whatsapp->value
                                ? 'וואטסאפ נשלח לאט במכוון ('.config('billing.waha.broadcast_throttle_seconds').' שניות בין הודעה להודעה) כדי לא לסכן חסימה של המספר. לדיוור רחב עדיף אימייל.'
                                : 'האימייל נשלח בקבוצות דרך התור — מתאים לדיוור רחב.'),
                        Forms\Components\TextInput::make('subject')
                            ->label('נושא')
                            ->required()
                            ->maxLength(255)
                            ->helperText(fn (Forms\Get $get): ?string => $get('channel') === BroadcastChannel::Whatsapp->value
                                ? 'בוואטסאפ הנושא משמש לזיהוי פנימי בלבד — הלקוח רואה רק את התוכן.'
                                : null),
                        Forms\Components\Textarea::make('body')
                            ->label('תוכן')
                            ->required()
                            ->rows(8)
                            ->columnSpanFull(),
                    ])->columns(2),

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
     * The live "who will get this" line under the segment builder — the same
     * count the send confirmation shows, so nobody presses send without knowing
     * how many people are about to hear from them.
     */
    protected static function audienceSummary(Forms\Get $get): HtmlString
    {
        $channel = BroadcastChannel::tryFrom((string) $get('channel')) ?? BroadcastChannel::Email;

        $counts = app(BroadcastAudience::class)->summary($channel, [
            'status' => $get('segment.status'),
            'plan_ids' => $get('segment.plan_ids'),
            'customer_ids' => $get('segment.customer_ids'),
        ]);

        if ($counts['reachable'] === 0) {
            return new HtmlString('<span class="text-danger-600 font-semibold">אף לקוח לא יקבל את הדיוור הזה.</span>');
        }

        $missing = $channel === BroadcastChannel::Email ? 'בלי כתובת אימייל' : 'בלי מספר וואטסאפ';

        $line = '<span class="font-semibold text-success-600">'.$counts['reachable'].' לקוחות יקבלו את הדיוור.</span>';

        if ($counts['unreachable'] > 0) {
            $line .= '<br><span class="text-sm text-gray-500">'.$counts['unreachable'].' לקוחות תואמים לקהל אך ידולגו — '.$missing.'.</span>';
        }

        return new HtmlString($line);
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
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
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
