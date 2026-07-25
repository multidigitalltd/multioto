<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Filament\Support\DebtorActions;
use App\Models\Charge;
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
use Illuminate\Support\Carbon;

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

    protected static ?string $navigationLabel = 'גבייה ידנית (מנויים)';

    protected static ?string $title = 'גבייה ידנית של מנויים (העברה / הוראת קבע / צ׳קים)';

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
            ->query(Subscription::query()->manuallyCollected()->with(['customer', 'plan'])
                // The latest ACTUAL payment (charged_at) and the date it was DUE
                // (that charge's period_start) — one subselect each, no N+1 — so
                // the table can show when the customer really paid and how late,
                // not just the next rolled-forward due date.
                // charged_at is COALESCEd to created_at for legacy rows that
                // predate the charged_at stamp (same fallback the revenue
                // reports use) — old payments must not vanish from the screen.
                ->addSelect([
                    'last_paid_at' => Charge::query()
                        ->selectRaw('COALESCE(charged_at, created_at)')
                        ->whereColumn('subscription_id', 'subscriptions.id')
                        ->where('status', ChargeStatus::Succeeded)
                        ->orderByRaw('COALESCE(charged_at, created_at) DESC')
                        ->limit(1),
                    'last_paid_due_at' => Charge::query()
                        ->select('period_start')
                        ->whereColumn('subscription_id', 'subscriptions.id')
                        ->where('status', ChargeStatus::Succeeded)
                        ->orderByRaw('COALESCE(charged_at, created_at) DESC')
                        ->limit(1),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('plan_name')->label('מנוי')
                    ->state(fn (Subscription $record): string => $record->planName()),
                Tables\Columns\TextColumn::make('customer.payment_method')->label('אמצעי')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::METHOD_LABELS[$state] ?? '—'),
                Tables\Columns\TextColumn::make('amount')->label('סכום')
                    ->state(fn (Subscription $record): string => Money::ils($record->totalChargeAgorot())),
                Tables\Columns\TextColumn::make('next_charge_at')->label('מועד תשלום הבא')
                    ->date('d/m/Y')->placeholder('—')->sortable()
                    // Red once due/overdue — this is the "payment demand" cue.
                    ->color(fn (Subscription $record): string => $record->next_charge_at && $record->next_charge_at->isPast() ? 'danger' : 'gray')
                    ->description(fn (Subscription $record): ?string => $record->next_charge_at && $record->next_charge_at->isPast() ? 'לגבייה' : null),
                // When the customer ACTUALLY paid last time, and how late that
                // payment was vs its due date — the chronic-late-payer signal.
                Tables\Columns\TextColumn::make('last_paid_at')->label('שולם לאחרונה')
                    ->formatStateUsing(fn ($state): string => Carbon::parse($state)->format('d/m/Y'))
                    ->placeholder('טרם נרשם תשלום')
                    ->sortable()
                    ->color(fn (Subscription $record): string => self::lastPaymentLateDays($record) > 0 ? 'warning' : 'gray')
                    ->description(function (Subscription $record): ?string {
                        if ($record->last_paid_at === null) {
                            return null;
                        }

                        $late = self::lastPaymentLateDays($record);

                        return $late > 0 ? "באיחור של {$late} ימים" : 'שולם בזמן';
                    }),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
            ])
            // Newest charge date first: recently-paid subscriptions (rolled to a
            // future date) at the top, the oldest dates at the bottom. Overdue
            // rows stay visible via the red "לגבייה" cue and the "רק לגבייה
            // עכשיו" filter.
            ->defaultSort('next_charge_at', 'desc')
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
                    // Only offered when the subscription is actually due: once a
                    // payment is recorded, next_charge_at rolls into the future,
                    // so the button disappears and the period can't be invoiced
                    // twice. Overdue/ due-today rows keep it.
                    ->visible(fn (Subscription $record): bool => $record->next_charge_at !== null && $record->next_charge_at->isPast())
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

    /**
     * Days the LAST recorded payment came in after its due date (the paid
     * charge's period_start). 0 = on time / no data. Reads the subselect
     * aliases added to the table query.
     */
    private static function lastPaymentLateDays(Subscription $record): int
    {
        if ($record->last_paid_at === null || $record->last_paid_due_at === null) {
            return 0;
        }

        $late = Carbon::parse($record->last_paid_due_at)->startOfDay()
            ->diffInDays(Carbon::parse($record->last_paid_at)->startOfDay(), false);

        return max(0, (int) $late);
    }
}
