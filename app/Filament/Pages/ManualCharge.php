<?php

namespace App\Filament\Pages;

use App\Enums\TokenStatus;
use App\Models\Customer;
use App\Services\Billing\ManualChargeService;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Grid;
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
                        Toggle::make('new_customer')
                            ->label('לקוח חדש (לא קיים במערכת)')
                            ->live()
                            ->columnSpanFull(),

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

                        Grid::make(2)
                            ->visible(fn (Get $get): bool => (bool) $get('new_customer'))
                            ->schema([
                                TextInput::make('new_name')->label('שם הלקוח')->maxLength(120)
                                    ->required(fn (Get $get): bool => (bool) $get('new_customer')),
                                TextInput::make('new_email')->label('אימייל')->email()->maxLength(150),
                                TextInput::make('new_phone')->label('טלפון')->tel()->maxLength(30),
                                TextInput::make('new_business_number')->label('ח.פ / עוסק')->maxLength(30),
                                Toggle::make('new_vat_exempt')->label('פטור ממע״מ'),
                            ]),

                        Grid::make(2)
                            // Single-line charge: hidden once the operator adds
                            // itemised lines below (then the total is their sum).
                            ->visible(fn (Get $get): bool => blank($get('lines')))
                            ->schema([
                                TextInput::make('amount')
                                    ->label('סכום לחיוב (₪, כולל מע״מ)')
                                    ->numeric()->prefix('₪')->step('0.01')->minValue(0.1)->inputMode('decimal')
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
                                    ->label('מחיר ליחידה (₪, כולל מע״מ)')
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
                    ])
                    ->footerActions([
                        FormAction::make('submit')
                            ->label('בצע חיוב')
                            ->icon('heroicon-o-credit-card')
                            ->requiresConfirmation()
                            ->modalHeading('אישור חיוב')
                            ->modalDescription('אם ללקוח יש כרטיס שמור — הוא יחויב מיד מול קארדקום. אחרת ייווצר עמוד תשלום מאובטח. להמשיך?')
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

        if ($activeToken) {
            $this->chargeSavedToken($customer, $totalAgorot, $description, $notes, $lines);
        } else {
            $this->openPaymentPage($customer, $totalAgorot, $description, $notes, $lines);
        }
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

    /** Existing selected customer, or a freshly created one for a walk-in. */
    private function resolveCustomer(array $data): ?Customer
    {
        if (empty($data['new_customer'])) {
            return filled($data['customer_id'] ?? null) ? Customer::find($data['customer_id']) : null;
        }

        $name = trim((string) ($data['new_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $email = filled($data['new_email'] ?? null) ? $data['new_email'] : null;

        return ($email ? Customer::where('email', $email)->first() : null)
            ?? Customer::create([
                'name' => $name,
                'email' => $email,
                'phone' => $data['new_phone'] ?? null,
                'business_number' => $data['new_business_number'] ?? null,
                'vat_exempt' => (bool) ($data['new_vat_exempt'] ?? false),
            ]);
    }

    /**
     * Charge the customer's saved active token in the queue.
     *
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    private function chargeSavedToken(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = []): void
    {
        app(ManualChargeService::class)->chargeSavedToken($customer, $totalAgorot, $description, $notes, $lines);

        Notification::make()
            ->title('החיוב נשלח לעיבוד')
            ->body("הכרטיס השמור של {$customer->name} יחויב בסך ₪".number_format($totalAgorot / 100, 2).'. עקבו אחר התוצאה בעמוד "חיובים", והחשבונית בעמוד "חשבוניות".')
            ->success()->persistent()->send();

        $this->resetForm();
    }

    /**
     * Create a hosted Cardcom payment page for a customer without a saved card.
     *
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    private function openPaymentPage(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = []): void
    {
        try {
            $result = app(ManualChargeService::class)->createHostedPage($customer, $totalAgorot, $description, $notes, $lines);
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

    private function resetForm(): void
    {
        $this->form->fill(['new_customer' => false, 'description' => 'חיוב חד-פעמי']);
    }
}
