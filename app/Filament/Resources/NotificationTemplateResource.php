<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\NotificationTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Operator editor for outbound message templates (acknowledgement, resolved…)
 * per channel. Placeholders: {{customer_name}}, {{ticket_id}},
 * {{ticket_subject}}, {{business_name}}. Rows are seeded by app:seed-templates;
 * creating ad-hoc keys is intentionally not offered — unknown keys are never
 * sent, so the list stays exactly what the system can send.
 */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'הודעות אוטומטיות';

    protected static ?string $modelLabel = 'תבנית הודעה';

    protected static ?string $pluralModelLabel = 'תבניות הודעות';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?int $navigationSort = 60;

    /** Hebrew names for the template keys, so the list is self-explanatory. */
    public const KEY_LABELS = [
        'ticket.received' => 'אישור קבלת פנייה',
        'ticket.resolved' => 'עדכון על סיום טיפול',
        'ticket.reminder' => 'תזכורת לפנייה ממתינה',
        'ticket.autoclosed' => 'סגירת פנייה אוטומטית',
        'customer.welcome' => 'ברוכים הבאים ללקוח חדש',
        'payment.link' => 'קישור תשלום ללקוח',
        'payment.reminder' => 'תזכורת תשלום',
        'card.capture' => 'קישור להזנת כרטיס אשראי',
        'card.capture_debt' => 'קישור להזנת כרטיס — לקוח בחוב',
        'card.expiring' => 'כרטיס אשראי עומד לפוג',
        'domain.renewal' => 'תזכורת חידוש דומיין',
        'incident.auto_resolved' => 'תקלה זוהתה וטופלה אוטומטית',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->description('משתנים זמינים לפי סוג ההודעה: {{customer_name}} — שם הלקוח · {{business_name}} — שם העסק · בפניות: {{ticket_id}}, {{ticket_subject}} · בקישור כרטיס: {{plan}} — שם המנוי, {{amount}} — סכום, {{link}} — קישור · בקישור תשלום: {{amount}}, {{items}} — פירוט, {{payment_options}}. משתנה שאינו רלוונטי להודעה מסוימת יישאר ריק.')
                ->schema([
                    Forms\Components\TextInput::make('subject')
                        ->label('נושא (מייל בלבד)')
                        ->maxLength(150)
                        ->visible(fn (?NotificationTemplate $record) => $record?->channel === 'email'),
                    Forms\Components\Textarea::make('body')
                        ->label('תוכן ההודעה')
                        ->rows(8)
                        ->required(),
                    Forms\Components\Toggle::make('enabled')
                        ->label('פעיל — ההודעה נשלחת אוטומטית')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('הודעה')
                    ->formatStateUsing(fn (string $state) => self::KEY_LABELS[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('ערוץ')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'whatsapp' ? 'וואטסאפ' : 'מייל'),
                Tables\Columns\IconColumn::make('enabled')
                    ->label('פעיל')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('key')
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'edit' => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
