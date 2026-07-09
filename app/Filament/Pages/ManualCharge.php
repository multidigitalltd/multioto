<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Actions\Action as NotificationAction;
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

                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label('סכום לחיוב (₪, כולל מע״מ)')
                                ->numeric()->prefix('₪')->step('0.01')->minValue(0.1)->inputMode('decimal')
                                ->required(),
                            TextInput::make('description')
                                ->label('תיאור (יופיע בחשבונית)')
                                ->maxLength(120)->required(),
                        ]),
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

        $totalAgorot = (int) round(((float) ($data['amount'] ?? 0)) * 100);

        if ($totalAgorot <= 0) {
            Notification::make()->title('סכום לא תקין')->danger()->send();

            return;
        }

        $description = filled($data['description'] ?? null) ? $data['description'] : 'חיוב חד-פעמי';

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
            $this->chargeSavedToken($customer, $totalAgorot, $description);
        } else {
            $this->openPaymentPage($customer, $totalAgorot, $description);
        }
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

    /** Charge the customer's saved active token in the queue. */
    private function chargeSavedToken(Customer $customer, int $totalAgorot, string $description): void
    {
        [$net, $vat] = $this->splitVat($totalAgorot, (bool) $customer->vat_exempt);

        $charge = Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => $description,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        ProcessManualChargeJob::dispatch($charge->id);

        Notification::make()
            ->title('החיוב נשלח לעיבוד')
            ->body("הכרטיס השמור של {$customer->name} יחויב בסך ₪".number_format($totalAgorot / 100, 2).'. עקבו אחר התוצאה בעמוד "חיובים", והחשבונית בעמוד "חשבוניות".')
            ->success()->persistent()->send();

        $this->resetForm();
    }

    /** Create a hosted Cardcom payment page for a customer without a saved card. */
    private function openPaymentPage(Customer $customer, int $totalAgorot, string $description): void
    {
        [$net, $vat] = $this->splitVat($totalAgorot, (bool) $customer->vat_exempt);

        $charge = Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => $description,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        try {
            $lowProfile = app(CardcomClient::class)->createChargeLowProfile(
                $charge->id,
                $totalAgorot,
                $description,
                $customer->name,
                $customer->email,
                $customer->phone,
                route('billing.update-card.done', ['result' => 'success']),
                route('billing.update-card.done', ['result' => 'failed']),
                route('webhooks.cardcom', ['secret' => config('billing.cardcom.webhook_secret')]),
            );
        } catch (\Throwable $e) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'יצירת עמוד תשלום נכשלה']);
            Notification::make()->title('יצירת עמוד התשלום נכשלה')->body(Str::limit($e->getMessage(), 150))->danger()->send();

            return;
        }

        if (blank($lowProfile['url'])) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'קארדקום לא החזירה כתובת תשלום']);
            Notification::make()->title('קארדקום לא החזירה עמוד תשלום')->danger()->send();

            return;
        }

        $charge->update(['cardcom_low_profile_id' => $lowProfile['low_profile_id']]);

        Notification::make()
            ->title('עמוד תשלום נוצר עבור '.$customer->name)
            ->body('ללקוח אין כרטיס שמור, לכן נוצר עמוד תשלום מאובטח. פִּתחו אותו כאן להזנת כרטיס, או העתיקו ושִלחו את הקישור ללקוח: '.$lowProfile['url'])
            ->success()->persistent()
            ->actions([
                NotificationAction::make('open')->label('פתח עמוד תשלום')->url($lowProfile['url'], shouldOpenInNewTab: true),
            ])
            ->send();

        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->form->fill(['new_customer' => false, 'description' => 'חיוב חד-פעמי']);
    }

    /**
     * Split a VAT-inclusive total into net + VAT agorot. Exempt customers pay no
     * VAT, so the whole amount is net.
     *
     * @return array{0: int, 1: int}
     */
    private function splitVat(int $totalAgorot, bool $vatExempt): array
    {
        if ($vatExempt) {
            return [$totalAgorot, 0];
        }

        $vatRate = (float) config('billing.vat_rate');
        $net = (int) round($totalAgorot / (1 + $vatRate));

        return [$net, $totalAgorot - $net];
    }
}
