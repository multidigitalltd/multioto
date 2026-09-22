<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Filament\Concerns\OpensNewCustomer;
use App\Filament\Concerns\OpensPaymentDemand;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\CustomerResource;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Billing\DemandDispatcher;
use App\Services\Linet\LinetClient;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
    use OpensNewCustomer;
    use OpensPaymentDemand;
    use RespectsModuleAccess;

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
                $this->newCustomerToggle(),
                Forms\Components\Select::make('customer_id')
                    ->label('לקוח')
                    ->options(fn (): array => Customer::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Forms\Get $get): bool => ! $get('new_customer'))
                    ->required(fn (Forms\Get $get): bool => ! $get('new_customer')),
                // הפרט שדרכו הדרישה נשלחת הוא חובה כבר כאן: לקוח שנפתח בלי מייל
                // ואז הדרישה אליו לא נשלחת — נשאר ככרטיס ריק שאיש לא ביקש.
                $this->newCustomerFields(
                    emailRequired: fn (Forms\Get $get): bool => (bool) $get('new_customer') && ($get('channel') ?? 'email') === 'email',
                    phoneRequired: fn (Forms\Get $get): bool => (bool) $get('new_customer') && $get('channel') === 'whatsapp',
                ),
                ...$this->demandFields(),
            ])
            ->action(function (array $data): void {
                // הסכום נבדק לפני פתיחת הלקוח: דרישה שלא תצא ממילא אינה סיבה
                // להשאיר במערכת כרטיס לקוח שנפתח לחינם.
                if ($this->demandTotal($data) <= 0) {
                    Notification::make()->title('סכום לא תקין')->danger()->send();

                    return;
                }

                $customer = $this->resolveCustomer($data);

                if (! $customer) {
                    Notification::make()
                        ->title(empty($data['new_customer']) ? 'לקוח לא נמצא' : 'חסר שם ללקוח החדש')
                        ->danger()->send();

                    return;
                }

                $this->sendDemand($customer, $data);
            });
    }

    /**
     * A human-readable "sent at" history for a demand — one line per send
     * (initial demand + each reminder), newest first — for the column tooltip.
     */
    private function sendLogTooltip(Charge $charge): ?string
    {
        $log = $charge->demand_reminders_log ?? [];

        if ($log === []) {
            return null;
        }

        return collect($log)
            ->reverse()
            ->map(function (array $entry): string {
                $when = filled($entry['at'] ?? null) ? Carbon::parse($entry['at'])->format('d/m/Y H:i') : '—';
                $kind = ($entry['type'] ?? 'demand') === 'reminder' ? 'תזכורת' : 'דרישה';
                $channel = ($entry['channel'] ?? '') === 'whatsapp' ? 'וואטסאפ' : 'מייל';

                return "{$when} · {$kind} · {$channel}";
            })
            ->implode("\n");
    }

    /**
     * Does this demand have a card behind it that could be charged?
     *
     * Read from the flags loaded with the page, and following exactly the
     * precedence `Charge::resolveCustomer()` uses — the subscription's customer
     * first, then the charge's own. Answering it the other way round would put
     * the button in front of one customer's card while the job charges
     * another's.
     *
     * This decides only whether the button is OFFERED. What is actually charged
     * is decided again, authoritatively, inside the job — so a flag that has
     * gone stale since the page loaded ends in an honest "no active card"
     * rather than in a charge against the wrong token.
     */
    private function hasSavedCard(Charge $charge): bool
    {
        $flag = $charge->subscription_id !== null
            ? $charge->subscription_customer_has_card
            : $charge->customer_has_card;

        // The flags ride on the page's own query, so a row that reached here by
        // any other route simply has no such attribute — and reading an absent
        // one as "no card" would make the button quietly disappear for a
        // customer who has one. Absent means "ask properly", not "no".
        return $flag === null ? $charge->chargeableToken() !== null : (bool) $flag;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::baseQuery()
                ->with(['customer', 'subscription.customer', 'invoice'])
                // Whether a saved card exists, asked once for the whole page
                // rather than per row: "does this customer hold an active
                // token" is read for every line to decide whether the charge
                // button belongs there, and asking it row by row is two
                // queries a line on a screen that polls every 15 seconds.
                ->withExists([
                    'customer as customer_has_card' => fn (Builder $q) => $q
                        ->whereHas('paymentTokens', fn (Builder $t) => $t->where('status', TokenStatus::Active)),
                    'subscription as subscription_customer_has_card' => fn (Builder $q) => $q
                        ->whereHas('customer.paymentTokens', fn (Builder $t) => $t->where('status', TokenStatus::Active)),
                ]))
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
                    ->label('תזכורות')->badge()
                    // Paused demands are flagged (⏸, red) so it's clear at a glance
                    // that the automatic nudges were stopped for this one.
                    ->color(fn (Charge $r): string => $r->demand_reminders_paused ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state, Charge $record): string => $record->demand_reminders_paused ? "{$state} ⏸" : (string) $state)
                    // Hover to see exactly when each demand/reminder went out.
                    ->tooltip(fn (Charge $r): ?string => $this->sendLogTooltip($r)),
                Tables\Columns\TextColumn::make('due_at')
                    ->label('לתשלום עד')->date('d/m/Y')->placeholder('—')->sortable()
                    // An open demand past its due date is flagged red so overdue money
                    // stands out. "Pay by" includes the due day, so only a date strictly
                    // before today counts as overdue.
                    ->color(fn (Charge $r): string => $r->status === ChargeStatus::Pending
                        && $r->due_at !== null && $r->due_at->lt(now()->startOfDay()) ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('demand_sent_at')
                    ->label('נשלחה לאחרונה')->dateTime('d/m/Y H:i')->sortable(),
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

                // Pause / resume the AUTOMATIC reminders for this one demand,
                // without canceling it — it stays open and payable, and a manual
                // "שלח תזכורת" still works. Use when the customer asked for time.
                Tables\Actions\Action::make('toggleReminders')
                    ->label(fn (Charge $r): string => $r->demand_reminders_paused ? 'חדש תזכורות' : 'עצור תזכורות')
                    ->icon(fn (Charge $r): string => $r->demand_reminders_paused ? 'heroicon-o-bell' : 'heroicon-o-bell-slash')
                    ->color('gray')
                    ->visible(fn (Charge $r): bool => $r->status === ChargeStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Charge $r): string => $r->demand_reminders_paused ? 'חידוש תזכורות אוטומטיות' : 'עצירת תזכורות אוטומטיות')
                    ->modalDescription(fn (Charge $r): string => $r->demand_reminders_paused
                        ? 'התזכורות האוטומטיות לדרישה זו יחזרו לפעול (כל 3 ימים עד לתשלום).'
                        : 'התזכורות האוטומטיות לדרישה זו ייעצרו. הדרישה תישאר פתוחה — עדיין אפשר לסמן כשולם, לבטל, או לשלוח תזכורת ידנית.')
                    ->action(function (Charge $record): void {
                        $paused = ! $record->demand_reminders_paused;
                        $record->update(['demand_reminders_paused' => $paused]);

                        Notification::make()
                            ->title($paused ? 'התזכורות האוטומטיות נעצרו לדרישה זו' : 'התזכורות האוטומטיות חודשו')
                            ->success()->send();
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

                // Take the money now, from the card already on file.
                //
                // A demand deliberately never auto-charges: it asks the customer
                // to pay, by transfer or through a link, and waits. This is the
                // deliberate override for the call that ends "just take it off
                // the card" — until now that meant retyping the whole charge on
                // the חיוב ידני screen, which leaves two rows for one debt and
                // an open demand still sending reminders.
                //
                // It runs through the same job as every other one-off charge, so
                // it inherits the same guarantees: a per-charge lock, only a
                // still-pending row is touched, every Cardcom response is
                // written to the row, and the invoice is issued only on success.
                Tables\Actions\Action::make('chargeSavedCard')
                    ->label('חייב מהכרטיס השמור')
                    ->icon('heroicon-o-credit-card')->color('success')
                    ->visible(fn (Charge $r): bool => $r->status === ChargeStatus::Pending && $this->hasSavedCard($r))
                    ->requiresConfirmation()
                    ->modalHeading('חיוב מיידי מהכרטיס השמור')
                    // The last sentence is the one that matters, and it is said
                    // because it is true: Cardcom offers no way to cancel a
                    // payment session, so a page the customer already has open
                    // stays payable. Better that the operator knows the window
                    // exists than believes this button closed it.
                    ->modalDescription(fn (Charge $r): string => implode(' ', array_filter([
                        'הכרטיס השמור של הלקוח יחויב עכשיו ב-'.Money::ils($r->total_agorot).'.',
                        'עם הצלחת החיוב תיסגר הדרישה, התזכורות ייפסקו, קישור התשלום יפסיק לעבוד ותונפק חשבונית מס/קבלה.',
                        'ודאו שהלקוח לא כבר שילם בהעברה — הוא התבקש לשלם בעצמו.',
                        filled($r->cardcom_pay_url)
                            ? 'כמו כן, עמוד תשלום שכבר נפתח אצל הלקוח נשאר פתוח לתשלום עד שיפוג — אם ישלם בו אחרי החיוב, תישלח התראה לזיכוי.'
                            : null,
                    ])))
                    ->modalSubmitActionLabel('חייב עכשיו')
                    ->action(function (Charge $record): void {
                        // Re-read rather than trust the row the page rendered:
                        // the customer may have paid the link in the meantime,
                        // and the table only refreshes every 15 seconds.
                        if ($record->fresh()->status !== ChargeStatus::Pending) {
                            Notification::make()->title('הסטטוס כבר השתנה — לא בוצע חיוב')->warning()->send();

                            return;
                        }

                        if ($record->chargeableToken() === null) {
                            Notification::make()
                                ->title('אין ללקוח כרטיס פעיל שמור')
                                ->body('ייתכן שהכרטיס הוחלף או פג תוקפו מאז שהמסך נטען. שלחו ללקוח קישור להזנת כרטיס.')
                                ->danger()->send();

                            return;
                        }

                        ProcessManualChargeJob::dispatch($record->id);

                        Notification::make()
                            ->title('החיוב נשלח לביצוע')
                            ->body('החיוב רץ ברקע מול קארדקום. התוצאה תופיע כאן תוך שניות — ואם יצליח, תונפק חשבונית מס/קבלה.')
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
