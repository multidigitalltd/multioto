<?php

namespace App\Filament\Pages;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Filament\Concerns\OpensNewCustomer;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Billing\ManualChargeService;
use App\Support\InstallmentSplit;
use App\Support\Money;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * חיוב ידני — טופס אחד לחיוב חד-פעמי של כל לקוח:
 *  - לקוח קיים עם כרטיס שמור → מחויב מיד (ברקע), ולינט מנפיק חשבונית.
 *  - לקוח קיים ללא כרטיס, או לקוח חדש → נוצר עמוד תשלום מאובטח של קארדקום;
 *    פותחים אותו כאן להזנת כרטיס, או שולחים את הקישור ללקוח.
 *
 * כרטיסי אשראי לעולם אינם עוברים דרך המערכת — הכרטיס מוזן רק בעמוד של קארדקום.
 */
class ManualCharge extends Page implements HasForms
{
    use InteractsWithForms;
    use OpensNewCustomer;
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'חיוב ידני';

    protected static ?string $title = 'חיוב ידני';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.manual-charge';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Cardcom hosted payment page to embed in an iframe after "בצע חיוב". */
    public ?string $paymentUrl = null;

    public function mount(): void
    {
        $this->form->fill(['new_customer' => false, 'description' => 'חיוב חד-פעמי']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('פרטי החיוב')
                    ->description('בוחרים לקוח קיים או פותחים לקוח חדש, ממלאים סכום ותיאור, ולוחצים "בצע חיוב". אם ללקוח יש כרטיס שמור הוא יחויב מיד; אחרת ייווצר עמוד תשלום מאובטח שאפשר לפתוח כאן או לשלוח ללקוח.')
                    ->schema([
                        $this->newCustomerToggle(),

                        Select::make('customer_id')
                            ->label('לקוח קיים')
                            ->options(fn (): array => Customer::query()
                                ->orderBy('name')
                                ->withCount(['paymentTokens as active_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Active)])
                                ->get()
                                ->mapWithKeys(fn (Customer $c): array => [
                                    $c->id => $c->name.($c->active_tokens_count > 0 ? '  —  כרטיס שמור ✓' : ''),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => ! $get('new_customer'))
                            ->required(fn (Get $get): bool => ! $get('new_customer'))
                            ->helperText('לקוחות עם "כרטיס שמור ✓" יחויבו מיד; לאחרים ייווצר עמוד תשלום.')
                            ->columnSpanFull(),

                        $this->newCustomerFields(),

                        Grid::make(2)
                            // Single-line charge: hidden once the operator adds
                            // itemised lines below (then the total is their sum).
                            ->visible(fn (Get $get): bool => blank($get('lines')))
                            ->schema([
                                TextInput::make('amount')
                                    // The label follows the toggle below, because
                                    // "500 plus VAT" and "500 all in" are the two
                                    // ways a price is agreed on the phone, and a
                                    // fixed label makes one of them arithmetic the
                                    // operator has to do in their head.
                                    ->label(fn (Get $get): string => $get('amount_excludes_vat')
                                        ? 'סכום לחיוב (₪, לפני מע״מ)'
                                        : 'סכום לחיוב (₪, כולל מע״מ)')
                                    ->numeric()->prefix('₪')->step('0.01')->minValue(0.1)->inputMode('decimal')
                                    ->live(onBlur: true)
                                    ->required(fn (Get $get): bool => blank($get('lines'))),
                                TextInput::make('description')
                                    ->label('תיאור (יופיע בחשבונית)')
                                    ->maxLength(120)->required(fn (Get $get): bool => blank($get('lines'))),
                            ]),

                        // Optional itemised invoice — several lines instead of one.
                        // When any line is added the charge total is their sum.
                        Repeater::make('lines')
                            ->label('פירוט שורות לחשבונית (אופציונלי)')
                            ->helperText('הוסיפו שורה אחת או יותר לחשבונית מפורטת. אם ריק — נעשה שימוש בסכום ובתיאור למעלה.')
                            ->schema([
                                TextInput::make('name')
                                    ->label('תיאור השורה')->maxLength(120)->required()
                                    ->columnSpan(2),
                                TextInput::make('qty')
                                    ->label('כמות')->numeric()->default(1)->minValue(1)->step(1)->required(),
                                TextInput::make('unit_price')
                                    ->label(fn (Get $get): string => $get('../../amount_excludes_vat')
                                        ? 'מחיר ליחידה (₪, לפני מע״מ)'
                                        : 'מחיר ליחידה (₪, כולל מע״מ)')
                                    ->numeric()->prefix('₪')->step('0.01')->minValue(0.01)->inputMode('decimal')->required(),
                            ])
                            ->columns(4)
                            ->addActionLabel('הוסף שורה')
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->columnSpanFull(),

                        Textarea::make('invoice_notes')
                            ->label('הערות לחשבונית (אופציונלי)')
                            ->helperText('טקסט חופשי שיודפס מתחת לשורה בחשבונית — למשל פירוט השירות או תקופה.')
                            ->rows(2)->maxLength(500)
                            ->columnSpanFull(),

                        Toggle::make('amount_excludes_vat')
                            ->label('הסכומים שהזנתי הם לפני מע״מ')
                            ->helperText('סמנו כשסיכמתם עם הלקוח מחיר "פלוס מע״מ" — המערכת תוסיף אותו. חל גם על שורות הפירוט.')
                            ->live()
                            ->visible(fn (Get $get): bool => ! $get('vat_exempt'))
                            ->columnSpanFull(),

                        // The three numbers, always visible: what the invoice
                        // shows, what the tax authority gets, and what actually
                        // leaves the card. Guessing which of the three the field
                        // meant is the whole confusion this replaces.
                        Placeholder::make('vat_breakdown')
                            ->label('פירוט החיוב')
                            ->content(fn (Get $get): string => $this->vatBreakdown($get))
                            ->columnSpanFull(),

                        Toggle::make('vat_exempt')
                            ->label('פטור ממע״מ')
                            ->helperText('סמנו אם החיוב הספציפי הזה פטור ממע״מ (מבטל את חישוב המע״מ והחשבונית תונפק כפטורה).')
                            ->live()
                            ->columnSpanFull(),

                        // פריסה לתשלומים. הסכום שלמעלה הוא החוב הכולל, וכאן
                        // נקבע לכמה חודשים הוא מתחלק. ריק = חיוב חד-פעמי,
                        // בדיוק כפי שהיה.
                        TextInput::make('installments')
                            ->label('פריסה לתשלומים חודשיים (אופציונלי)')
                            ->numeric()->minValue(1)->maxValue(120)->step(1)
                            ->live(onBlur: true)
                            ->helperText('ריק או 1 = חיוב חד-פעמי. מספר גדול יותר = הסכום שלמעלה ייגבה בתשלומים חודשיים שווים, כמנוי שנסגר מעצמו אחרי התשלום האחרון.')
                            ->columnSpanFull(),
                        Placeholder::make('installments_preview')
                            ->label('מה ייגבה')
                            ->content(fn (Get $get): string => $this->installmentsPreview($get))
                            ->visible(fn (Get $get): bool => (int) $get('installments') > 1)
                            ->columnSpanFull(),
                    ])
                    ->footerActions([
                        FormAction::make('submit')
                            ->label(fn (Get $get): string => (int) $get('installments') > 1 ? 'פתח פריסת תשלומים' : 'בצע חיוב')
                            ->icon('heroicon-o-credit-card')
                            ->requiresConfirmation()
                            ->modalHeading(fn (Get $get): string => (int) $get('installments') > 1 ? 'אישור פריסת תשלומים' : 'אישור חיוב')
                            ->modalDescription(fn (Get $get): string => (int) $get('installments') > 1
                                ? 'ייפתח מנוי פריסה על הכרטיס השמור של הלקוח, התשלום הראשון ייגבה עכשיו והשאר ייגבו מדי חודש עד לתשלום האחרון. להמשיך?'
                                : 'אם ללקוח יש כרטיס שמור — הוא יחויב מיד מול קארדקום. אחרת ייווצר עמוד תשלום מאובטח. להמשיך?')
                            ->modalSubmitActionLabel('המשך')
                            ->action(fn () => $this->submit()),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Resolve (or create) the customer, then either charge a saved active token
     * immediately or open a hosted Cardcom payment page.
     */
    public function submit(): void
    {
        $data = $this->form->getState();

        // Itemised lines (if any) drive the total; otherwise the single amount.
        $lines = $this->normalizeLines($data['lines'] ?? []);

        if ($lines !== []) {
            $totalAgorot = array_sum(array_map(fn (array $l): int => $l['qty'] * $l['unit_price_agorot'], $lines));
            $description = $lines[0]['name'];
        } else {
            $totalAgorot = (int) round(((float) ($data['amount'] ?? 0)) * 100);
            $description = filled($data['description'] ?? null) ? $data['description'] : 'חיוב חד-פעמי';
        }

        if ($totalAgorot <= 0) {
            Notification::make()->title('סכום לא תקין')->danger()->send();

            return;
        }

        // Everything downstream — the charge, the payment page, the invoice —
        // works in VAT-inclusive totals. Prices agreed as "plus VAT" are turned
        // into one here, at the single point where the operator's intent is
        // still known.
        $totalAgorot = $this->grossFromForm($totalAgorot, $data);

        $notes = filled($data['invoice_notes'] ?? null) ? trim((string) $data['invoice_notes']) : null;

        $customer = $this->resolveCustomer($data);

        if (! $customer) {
            Notification::make()->title('בחרו לקוח קיים או מלאו שם ללקוח חדש')->danger()->send();

            return;
        }

        // The "new customer" (enter/collect a card) path must always go through
        // the hosted page — never silently charge a stored card, even if the
        // entered email happens to match an existing card-holding customer.
        $viaNewCustomer = (bool) ($data['new_customer'] ?? false);
        $activeToken = ! $viaNewCustomer
            && $customer->paymentTokens()->where('status', TokenStatus::Active)->exists();

        $vatExempt = (bool) ($data['vat_exempt'] ?? false);

        // פריסה לתשלומים היא מסלול אחר לגמרי: לא חיוב אחד אלא מנוי שגובה
        // בכל חודש ונסגר בסופו. הוא מטופל כאן ומחזיר, כדי שהמסלול החד-פעמי
        // שלמטה יישאר בדיוק כפי שהיה.
        if ((int) ($data['installments'] ?? 0) > 1) {
            $this->openInstallmentPlan(
                $customer, $totalAgorot, (int) $data['installments'], $description, $vatExempt, $activeToken,
            );

            return;
        }

        if ($activeToken) {
            $this->chargeSavedToken($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);
        } else {
            $this->openPaymentPage($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);
        }
    }

    /**
     * The amount that will actually be taken, from what was typed.
     *
     * @param  array<string, mixed>  $data
     */
    private function grossFromForm(int $agorot, array $data): int
    {
        $exempt = (bool) ($data['vat_exempt'] ?? false);
        $excludesVat = (bool) ($data['amount_excludes_vat'] ?? false);

        if ($exempt || ! $excludesVat) {
            return $agorot;
        }

        return (int) round($agorot * (1 + (float) config('billing.vat_rate')));
    }

    /**
     * The three numbers behind one field: net, VAT, and what leaves the card.
     *
     * Shown always, not only when something looks odd. "Which of the three did
     * that box mean" is the question this screen kept producing, and a number
     * somebody has to reverse-engineer is a number they will eventually get
     * wrong on an invoice.
     */
    private function vatBreakdown(Get $get): string
    {
        $lines = $this->normalizeLines($get('lines') ?? []);

        $typed = $lines !== []
            ? array_sum(array_map(fn (array $l): int => $l['qty'] * $l['unit_price_agorot'], $lines))
            : (int) round(((float) ($get('amount') ?? 0)) * 100);

        if ($typed <= 0) {
            return 'הזינו סכום כדי לראות את הפירוט.';
        }

        if ((bool) $get('vat_exempt')) {
            return 'פטור ממע״מ · לתשלום '.Money::ils($typed);
        }

        $rate = (float) config('billing.vat_rate');
        $gross = (bool) $get('amount_excludes_vat') ? (int) round($typed * (1 + $rate)) : $typed;
        $net = (int) round($gross / (1 + $rate));

        return 'לפני מע״מ '.Money::ils($net)
            .' · מע״מ '.Money::ils($gross - $net)
            .' · לתשלום בפועל '.Money::ils($gross);
    }

    /**
     * Normalise repeater rows to integer-agorot invoice lines, dropping empties.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{name: string, qty: int, unit_price_agorot: int}>
     */
    private function normalizeLines(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): array => [
                'name' => trim((string) ($row['name'] ?? '')),
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
                'unit_price_agorot' => (int) round(((float) ($row['unit_price'] ?? 0)) * 100),
            ])
            ->filter(fn (array $line): bool => $line['name'] !== '' && $line['unit_price_agorot'] > 0)
            ->values()
            ->all();
    }

    /**
     * Charge the customer's saved active token in the queue.
     *
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    private function chargeSavedToken(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], bool $vatExempt = false): void
    {
        app(ManualChargeService::class)->chargeSavedToken($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);

        Notification::make()
            ->title('החיוב נשלח לעיבוד')
            ->body("הכרטיס השמור של {$customer->name} יחויב בסך ".Money::ils($totalAgorot).'. עקבו אחר התוצאה בעמוד "חיובים", והחשבונית בעמוד "חשבוניות".')
            ->success()->persistent()->send();

        $this->resetForm();
    }

    /**
     * Create a hosted Cardcom payment page for a customer without a saved card.
     *
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    private function openPaymentPage(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], bool $vatExempt = false): void
    {
        try {
            $result = app(ManualChargeService::class)->createHostedPage($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);
        } catch (\Throwable $e) {
            Notification::make()->title('פתיחת עמוד התשלום נכשלה')->body(Str::limit($e->getMessage(), 150))->danger()->send();

            return;
        }

        // Embed Cardcom's secure page in an iframe on this screen (below), so the
        // operator/customer enters the card without leaving the system.
        $this->paymentUrl = $result['url'];

        Notification::make()
            ->title('עמוד תשלום נפתח עבור '.$customer->name)
            ->body('הזינו את הכרטיס בחלון המאובטח שנפתח למטה. לאחר התשלום החיוב יתעדכן והחשבונית תופק אוטומטית.')
            ->success()
            ->send();

        $this->resetForm();
    }

    /**
     * מה ייגבה בפועל בפריסה — לפני שפותחים אותה.
     *
     * הסכום נגבה בסכומים שווים, ולכן חוב שאינו מתחלק בדיוק ייגבה בסך הכל מעט
     * אחרת ממה שהוזן. עדיף לדעת את זה כאן מאשר כשהלקוח סופר.
     */
    private function installmentsPreview(Get $get): string
    {
        $count = (int) $get('installments');
        // The same conversion the submit does, so the preview and the charge
        // can never quote two different numbers.
        $totalAgorot = $this->grossFromForm((int) round(((float) $get('amount')) * 100), [
            'vat_exempt' => (bool) $get('vat_exempt'),
            'amount_excludes_vat' => (bool) $get('amount_excludes_vat'),
        ]);

        if ($count < 2 || $totalAgorot < 1) {
            return '—';
        }

        $split = InstallmentSplit::compute(
            $totalAgorot,
            $count,
            (bool) $get('vat_exempt') ? 0.0 : (float) config('billing.vat_rate'),
            totalIncludesVat: true,
        );

        return InstallmentSplit::describe($split, $count);
    }

    /**
     * פתיחת פריסת תשלומים: מנוי ייעודי שגובה בכל חודש ונסגר אחרי האחרון.
     *
     * דורש כרטיס שמור, ולא במקרה. עמוד התשלום המאובטח של קארדקום גובה פעם אחת
     * ואינו שומר כרטיס (ChargeOnly), כך שפריסה שתיפתח בלעדיו הייתה גובה תשלום
     * אחד ואז נתקעת בלי אמצעי גבייה — עם לקוח שכבר סוכם איתו על פריסה. עדיף
     * לומר את זה מראש.
     */
    private function openInstallmentPlan(
        Customer $customer,
        int $totalAgorot,
        int $count,
        string $description,
        bool $vatExempt,
        bool $activeToken,
    ): void {
        if (! $activeToken) {
            Notification::make()
                ->title('אין כרטיס שמור ללקוח')
                ->body('פריסה לתשלומים גובה כל חודש, ולכן היא דורשת כרטיס שמור. שלחו ללקוח קישור להזנת כרטיס מכרטיס הלקוח, ואז פתחו כאן את הפריסה.')
                ->warning()->persistent()->send();

            return;
        }

        $split = InstallmentSplit::compute(
            $totalAgorot,
            $count,
            $vatExempt ? 0.0 : (float) config('billing.vat_rate'),
            totalIncludesVat: true,
        );

        if ($split['per_charge_agorot'] < 1) {
            Notification::make()->title('לא ניתן לחשב את הפריסה')->danger()->send();

            return;
        }

        $subscription = Subscription::create([
            'customer_id' => $customer->id,
            'plan_id' => null,
            'name' => Str::limit($description, 180, ''),
            'billing_interval' => BillingInterval::Monthly,
            // המחיר שנשמר הוא לפני מע״מ; המע״מ נוסף בזמן החיוב.
            'price_agorot_override' => $split['base_agorot'],
            'vat_applies' => ! $vatExempt,
            'installments_total' => $count,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
            // התשלום הראשון נגבה עכשיו; השאר בכל חודש אחריו.
            'next_charge_at' => now(),
        ]);

        ChargeSubscriptionJob::dispatch($subscription->id, manual: true);

        Notification::make()
            ->title('נפתחה פריסת תשלומים ל'.$customer->name)
            ->body(InstallmentSplit::describe($split, $count).' — התשלום הראשון נשלח לגבייה עכשיו, והשאר ייגבו מדי חודש. הפריסה תיסגר מעצמה אחרי התשלום האחרון.')
            ->success()->persistent()->send();

        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->form->fill(['new_customer' => false, 'description' => 'חיוב חד-פעמי']);
    }
}
