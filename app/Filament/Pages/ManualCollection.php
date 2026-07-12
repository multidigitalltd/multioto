<?php

namespace App\Filament\Pages;

use App\Filament\Support\DebtorActions;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionCollectionService;
use App\Support\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * דרישות תשלום — מעקב גבייה ידנית: מנויים שמשלמים בהעברה בנקאית, הוראת קבע או
 * צ׳קים (ללא כרטיס שמור), שהמערכת לא מחייבת אוטומטית. כאן רואים מתי כל אחד אמור
 * לשלם, ומי כבר בפיגור, ומסמנים "שולם" — פעולה אחת שמתעדת את התשלום, מגלגלת את
 * המנוי לתקופה הבאה ומפיקה חשבונית. כך גבייה בהעברה/הו״ק לא מתפספסת.
 */
class ManualCollection extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'דרישות תשלום';

    protected static ?string $title = 'דרישות תשלום — גבייה ידנית (העברה / הוראת קבע / צ׳קים)';

    protected static ?int $navigationSort = 22;

    protected static string $view = 'filament.pages.collections';

    private const METHOD_LABELS = [
        'standing_order' => 'הוראת קבע',
        'bank_transfer' => 'העברה בנקאית',
        'checks' => 'צ׳קים',
    ];

    /** Amber badge with the count of subscriptions due for manual collection. */
    public static function getNavigationBadge(): ?string
    {
        $count = Subscription::query()->dueForManualCollection()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Subscription::query()->manuallyCollected()->with(['customer', 'plan']))
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('plan_name')->label('מנוי')
                    ->state(fn (Subscription $record): string => $record->planName()),
                Tables\Columns\TextColumn::make('customer.payment_method')->label('אמצעי')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::METHOD_LABELS[$state] ?? '—'),
                Tables\Columns\TextColumn::make('amount')->label('סכום')
                    ->state(fn (Subscription $record): string => Money::ils($record->totalChargeAgorot())),
                Tables\Columns\TextColumn::make('next_charge_at')->label('מועד תשלום')
                    ->date('d/m/Y')->placeholder('—')->sortable()
                    // Red once due/overdue — this is the "payment demand" cue.
                    ->color(fn (Subscription $record): string => $record->next_charge_at && $record->next_charge_at->isPast() ? 'danger' : 'gray')
                    ->description(fn (Subscription $record): ?string => $record->next_charge_at && $record->next_charge_at->isPast() ? 'לגבייה' : null),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
            ])
            ->defaultSort('next_charge_at', 'asc')
            ->filters([
                Tables\Filters\Filter::make('due')
                    ->label('רק לגבייה עכשיו')
                    ->query(fn ($query) => $query->where('next_charge_at', '<=', now())),
            ])
            ->actions([
                Tables\Actions\Action::make('markPaid')
                    ->label('סמן כשולם + חשבונית')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('רישום תשלום והפקת חשבונית')
                    ->modalDescription('פעולה זו מתעדת שהמנוי שולם עבור התקופה הנוכחית, מגלגלת אותו לתקופה הבאה, ומפיקה חשבונית מס/קבלה בלינט.')
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('הערה לחשבונית (אופציונלי)')
                            ->rows(2)
                            ->placeholder('לדוגמה: התקבל בהעברה בנקאית / אסמכתא 12345'),
                    ])
                    ->action(function (Subscription $record, array $data): void {
                        $charge = app(SubscriptionCollectionService::class)->recordPayment($record, $data['notes'] ?? null);

                        Notification::make()
                            ->title('התשלום נרשם — החשבונית מופקת ברקע')
                            ->body(Money::ils($charge->total_agorot).' · המנוי גולגל לתקופה הבאה.')
                            ->success()
                            ->send();
                    }),
                DebtorActions::viewCustomer(),
            ])
            ->emptyStateHeading('אין דרישות תשלום פתוחות')
            ->emptyStateDescription('כל המנויים בגבייה ידנית מעודכנים.');
    }
}
