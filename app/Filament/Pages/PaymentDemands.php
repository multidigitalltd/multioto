<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Filament\Resources\CustomerResource;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Billing\DemandDispatcher;
use App\Services\Linet\LinetClient;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * דרישות תשלום — הוצאת "חשבונית עסקה" (פרופורמה) ללקוח ומעקב עד לתשלום. פותחים
 * דרישה עם סכום ופירוט, הלקוח מקבל מייל עם קישור לתשלום ידני (לא נגבה אוטומטית
 * גם אם יש כרטיס שמור) ופרטי העברה בנקאית, והמערכת "נודנקת" עד שמשולם — ואז
 * החשבונית עסקה נסגרת ומונפקת חשבונית מס/קבלה.
 */
class PaymentDemands extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'דרישות תשלום';

    protected static ?string $title = 'דרישות תשלום (חשבונית עסקה)';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'filament.pages.collections';

    /** Amber badge with the count of still-pending demands. */
    public static function getNavigationBadge(): ?string
    {
        $count = self::baseQuery()->where('status', ChargeStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Every charge that was sent to a customer as a payment demand. */
    protected static function baseQuery(): Builder
    {
        return Charge::query()->whereNotNull('demand_sent_at');
    }

    protected function getHeaderActions(): array
    {
        return [$this->newDemandAction()];
    }

    /**
     * Open a new payment demand: issues the proforma, and emails the customer a
     * manual payment link (never auto-charges a saved card) plus bank-transfer
     * details. Mirrors the customer-screen "שליחת קישור תשלום" but always offers
     * both a link and a transfer, since a demand is a formal request to pay.
     */
    private function newDemandAction(): Action
    {
        return Action::make('newDemand')
            ->label('דרישת תשלום חדשה')
            ->icon('heroicon-o-plus')
            ->modalWidth('2xl')
            ->form([
                Forms\Components\Select::make('customer_id')
                    ->label('לקוח')
                    ->options(fn (): array => Customer::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required(),
                Forms\Components\TextInput::make('description')
                    ->label('עבור (יופיע ללקוח ובחשבונית)')->default('תשלום')->maxLength(120)->required(),
                Forms\Components\Repeater::make('items')
                    ->label('פירוט פריטים (אופציונלי)')
                    ->helperText('הוסיפו פריטים כדי שהלקוח יראה פירוט לפי מוצר. אם ריק — תישלח שורת הסכום שלמטה.')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('פריט')->maxLength(120)->required()->columnSpan(2),
                        Forms\Components\TextInput::make('qty')->label('כמות')->numeric()->default(1)->minValue(1)->required(),
                        Forms\Components\TextInput::make('unit_price')->label('מחיר ליח׳ (₪, כולל מע״מ)')
                            ->numeric()->prefix('₪')->step('0.01')->minValue(0)->inputMode('decimal')->required(),
                    ])
                    ->columns(4)->addActionLabel('הוסף פריט')->default([]),
                Forms\Components\TextInput::make('amount')
                    ->label('סכום לתשלום (₪, כולל מע״מ)')
                    ->helperText('בשימוש רק כשאין פירוט פריטים.')
                    ->numeric()->prefix('₪')->step('0.01')->minValue(0)->inputMode('decimal')
                    ->requiredWithout('items'),
                Forms\Components\Radio::make('channel')
                    ->label('לשלוח דרך')
                    ->options(['email' => 'מייל', 'whatsapp' => 'וואטסאפ'])
                    ->default('email')->required()
                    ->helperText('הדרישה כוללת תמיד קישור לתשלום ידני ופרטי העברה בנקאית — לא מתבצע חיוב אוטומטי.'),
            ])
            ->action(function (array $data): void {
                $customer = Customer::find($data['customer_id']);

                if (! $customer) {
                    Notification::make()->title('לקוח לא נמצא')->danger()->send();

                    return;
                }

                $lines = $this->demandLines($data['items'] ?? []);
                $totalAgorot = $lines !== []
                    ? array_sum(array_map(fn (array $l): int => $l['qty'] * $l['unit_price_agorot'], $lines))
                    : (int) round(((float) ($data['amount'] ?? 0)) * 100);

                if ($totalAgorot <= 0) {
                    Notification::make()->title('סכום לא תקין')->danger()->send();

                    return;
                }

                $channel = $data['channel'] ?? 'email';
                $missing = $channel === 'email' ? blank($customer->email) : (blank($customer->whatsapp_jid) && blank($customer->phone));

                if ($missing) {
                    Notification::make()->title('אין ללקוח פרטי '.($channel === 'email' ? 'מייל' : 'וואטסאפ'))->danger()->send();

                    return;
                }

                // A demand always offers BOTH the (non-auto-charging) link and the
                // bank-transfer details; the proforma is issued by the job.
                SendPaymentLinkJob::dispatch($customer->id, $totalAgorot, filled($data['description']) ? $data['description'] : 'תשלום', $channel, $lines, ['link', 'transfer']);

                Notification::make()
                    ->title('דרישת התשלום נשלחה')
                    ->body('נוצרה חשבונית עסקה ונשלחה ל'.$customer->name.' ב'.($channel === 'email' ? 'מייל' : 'וואטסאפ').' עם קישור לתשלום ופרטי העברה. עם התשלום תיסגר החשבונית עסקה ותונפק חשבונית מס/קבלה.')
                    ->success()->send();
            });
    }

    /**
     * Normalise the items repeater into charge line rows (agorot), dropping blank
     * rows. Returns [] when nothing usable was entered.
     *
     * @param  array<int, array{name?: string, qty?: mixed, unit_price?: mixed}>  $items
     * @return array<int, array{name: string, qty: int, unit_price_agorot: int}>
     */
    private function demandLines(array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => [
                'name' => trim((string) ($item['name'] ?? '')),
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                'unit_price_agorot' => (int) round(((float) ($item['unit_price'] ?? 0)) * 100),
            ])
            ->filter(fn (array $line): bool => $line['name'] !== '' && $line['unit_price_agorot'] > 0)
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::baseQuery()->with(['customer', 'subscription.customer', 'invoice']))
            ->defaultSort('demand_sent_at', 'desc')
            ->poll('15s')
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('לקוח')->weight('bold')
                    ->getStateUsing(fn (Charge $r): ?string => $r->subscription?->customer?->name ?? $r->customer?->name),
                Tables\Columns\TextColumn::make('description')
                    ->label('עבור')->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->label('סכום')->money('ILS', divideBy: 100)->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')->badge()
                    ->formatStateUsing(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Succeeded => 'שולם',
                        ChargeStatus::Pending => 'ממתין לתשלום',
                        ChargeStatus::Canceled => 'בוטל',
                        ChargeStatus::Failed => 'נכשל',
                    })
                    ->color(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Succeeded => 'success',
                        ChargeStatus::Pending => 'warning',
                        ChargeStatus::Failed => 'danger',
                        ChargeStatus::Canceled => 'gray',
                    }),
                Tables\Columns\IconColumn::make('proforma_document_id')
                    ->label('חשבונית עסקה')->boolean()
                    ->trueIcon('heroicon-o-document-text')->falseIcon('heroicon-o-minus'),
                Tables\Columns\IconColumn::make('invoice_state')
                    ->label('חשבונית מס/קבלה')->boolean()
                    ->getStateUsing(fn (Charge $r): bool => $r->invoice()->exists())
                    ->trueIcon('heroicon-o-check-badge')->falseIcon('heroicon-o-minus'),
                Tables\Columns\TextColumn::make('demand_reminder_count')
                    ->label('תזכורות')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('demand_sent_at')
                    ->label('נשלחה')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')->options(ChargeStatus::class)->multiple()
                    ->default([ChargeStatus::Pending->value]),
            ])
            ->actions([
                // Nudge now — send a reminder immediately (email/WhatsApp per the
                // demand's channel), on top of the automatic daily reminders.
                Tables\Actions\Action::make('remindNow')
                    ->label('שלח תזכורת')
                    ->icon('heroicon-o-bell-alert')->color('warning')
                    ->visible(fn (Charge $r): bool => $r->status === ChargeStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading('שליחת תזכורת עכשיו')
                    ->modalDescription('תישלח ללקוח תזכורת לתשלום הדרישה (בנוסף לתזכורות האוטומטיות).')
                    ->action(function (Charge $record, DemandDispatcher $dispatcher): void {
                        if ($record->fresh()->status !== ChargeStatus::Pending) {
                            Notification::make()->title('הדרישה כבר אינה ממתינה')->warning()->send();

                            return;
                        }

                        $dispatcher->send($record, 'payment.reminder', $record->demand_channel ?: 'email', true);
                        // Bump the counter AND the last-contact time, so the daily
                        // SendDemandRemindersJob counts from now and doesn't fire
                        // again the same day (it keys off demand_sent_at).
                        $record->update([
                            'demand_reminder_count' => $record->demand_reminder_count + 1,
                            'demand_sent_at' => now(),
                        ]);

                        Notification::make()->title('התזכורת נשלחה')->success()->send();
                    }),

                // Record a manual payment (bank transfer / cash): a transfer never
                // reaches Cardcom, so this is the path that finalises the demand,
                // stops the reminders, and issues the tax invoice-receipt in Linet.
                Tables\Actions\Action::make('markPaid')
                    ->label('סמן כשולם')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Charge $r): bool => $r->status === ChargeStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading('סימון הדרישה כשולמה')
                    ->modalDescription('לשימוש כשהתקבל תשלום בהעברה בנקאית / מזומן. הדרישה תסומן כשולמה, התזכורות ייפסקו, ותונפק חשבונית מס/קבלה בלינט.')
                    ->modalSubmitActionLabel('סמן כשולם והנפק חשבונית')
                    ->action(function (Charge $record): void {
                        if ($record->fresh()->status !== ChargeStatus::Pending) {
                            Notification::make()->title('הסטטוס כבר השתנה')->warning()->send();

                            return;
                        }

                        $record->update(['status' => ChargeStatus::Succeeded, 'charged_at' => now(), 'failure_reason' => null]);
                        IssueInvoiceJob::dispatch($record->id);

                        Notification::make()
                            ->title('הדרישה סומנה כשולמה ✓')
                            ->body('חשבונית מס/קבלה מונפקת בלינט. עקבו במסך "חיובים".')
                            ->success()->send();
                    }),

                // Void a pending demand: its link stops working and it's no longer owed.
                Tables\Actions\Action::make('cancelDemand')
                    ->label('בטל דרישה')
                    ->icon('heroicon-o-x-circle')->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('ביטול דרישת תשלום')
                    ->modalDescription('הקישור שנשלח ללקוח יפסיק לעבוד ויציג "לא פעיל". לא ניתן לבטל אם התשלום כבר בוצע.')
                    ->visible(fn (Charge $r): bool => $r->status === ChargeStatus::Pending)
                    ->action(function (Charge $record): void {
                        if ($record->fresh()->status !== ChargeStatus::Pending) {
                            Notification::make()->title('לא ניתן לבטל — הסטטוס השתנה')->warning()->send();

                            return;
                        }

                        $record->update(['status' => ChargeStatus::Canceled, 'failure_reason' => 'הדרישה בוטלה ידנית']);
                        Notification::make()->title('הדרישה בוטלה — הקישור אינו פעיל עוד')->success()->send();
                    }),

                // Open the proforma (חשבונית עסקה) PDF, backfilling the link from Linet.
                Tables\Actions\Action::make('proformaPdf')
                    ->label('חשבונית עסקה PDF')
                    ->icon('heroicon-o-document-arrow-down')->color('gray')
                    ->visible(fn (Charge $r): bool => filled($r->proforma_document_id))
                    ->action(function (Charge $record, LinetClient $linet) {
                        if (blank($record->proforma_pdf_url)) {
                            try {
                                $record->update(['proforma_pdf_url' => $linet->documentPdfUrl($record->proforma_document_id)]);
                            } catch (\Throwable $e) {
                                Notification::make()->title('שליפת ה-PDF מלינט נכשלה')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                                return;
                            }
                        }

                        return blank($record->proforma_pdf_url)
                            ? Notification::make()->title('לינט לא החזירה קישור למסמך')->warning()->send()
                            : redirect()->away($record->proforma_pdf_url);
                    }),

                Tables\Actions\Action::make('viewCustomer')
                    ->label('לכרטיס הלקוח')
                    ->icon('heroicon-o-user')->color('gray')
                    ->url(fn (Charge $r): ?string => ($c = $r->subscription?->customer ?? $r->customer)
                        ? CustomerResource::getUrl('view', ['record' => $c]) : null),
            ])
            ->emptyStateHeading('אין דרישות תשלום')
            ->emptyStateDescription('פִּתחו דרישה חדשה בכפתור "דרישת תשלום חדשה".');
    }
}
