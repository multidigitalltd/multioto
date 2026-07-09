<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * חיוב ידני — לחייב לקוח בעל כרטיס שמור בסכום חד-פעמי, מחוץ למחזור המנוי.
 * החיוב נוצר כרשומת charge חד-פעמית (ללא מנוי) ומעובד ברקע: קארדקום מחייב את
 * הטוקן, ובהצלחה מונפקת חשבונית בלינט — בדיוק כמו חיוב מנוי.
 *
 * המע״מ מחושב מתוך הסכום הכולל לפי סטטוס הפטור של הלקוח. כרטיסי אשראי לעולם
 * אינם עוברים דרך המערכת — משתמשים בטוקן שנלכד קודם בעמוד המאובטח של קארדקום.
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
        $this->form->fill([
            'description' => 'חיוב ידני',
            'walkin_description' => 'חיוב חד-פעמי',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('חיוב לקוח עם כרטיס שמור')
                    ->description('מחייב את הכרטיס השמור של הלקוח. לאחר חיוב מוצלח מונפקת חשבונית בלינט אוטומטית. אם ללקוח אין כרטיס — שלחו לו קודם "קישור לכרטיס" מעמוד הלקוחות.')
                    ->schema([
                        Select::make('customer_id')
                            ->label('לקוח')
                            ->options(fn (): array => Customer::query()
                                ->whereHas('paymentTokens', fn ($q) => $q->where('status', TokenStatus::Active))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('מוצגים רק לקוחות עם כרטיס פעיל שמור.'),
                        TextInput::make('amount')
                            ->label('סכום לחיוב (₪, כולל מע״מ)')
                            ->numeric()
                            ->prefix('₪')
                            ->step('0.01')
                            ->minValue(0.1)
                            ->inputMode('decimal')
                            ->required(),
                        TextInput::make('description')
                            ->label('תיאור (יופיע בחשבונית)')
                            ->maxLength(120)
                            ->required(),
                    ])->columns(3)
                    ->footerActions([
                        FormAction::make('charge')
                            ->label('חייב עכשיו')
                            ->icon('heroicon-o-credit-card')
                            ->requiresConfirmation()
                            ->modalHeading('אישור חיוב')
                            ->modalDescription('החיוב יבוצע מיידית מול קארדקום ולא ניתן לביטול אוטומטי. להמשיך?')
                            ->modalSubmitActionLabel('חייב עכשיו')
                            ->action(fn () => $this->charge()),
                    ]),

                Section::make('חיוב לקוח מזדמן (ללא כרטיס שמור)')
                    ->description('ממלאים את פרטי הלקוח והסכום ויוצרים עמוד תשלום מאובטח של קארדקום. את פרטי הכרטיס מזינים בעמוד של קארדקום — הפקיד יכול לפתוח אותו כאן, או לשלוח את הקישור ללקוח. מספרי כרטיס לעולם אינם עוברים דרך המערכת.')
                    ->schema([
                        TextInput::make('walkin_name')->label('שם הלקוח')->required()->maxLength(120),
                        TextInput::make('walkin_email')->label('אימייל')->email()->maxLength(150),
                        TextInput::make('walkin_phone')->label('טלפון')->tel()->maxLength(30),
                        TextInput::make('walkin_business_number')->label('ח.פ / עוסק')->maxLength(30),
                        TextInput::make('walkin_amount')->label('סכום לחיוב (₪, כולל מע״מ)')->numeric()->prefix('₪')->step('0.01')->minValue(0.1)->inputMode('decimal')->required(),
                        TextInput::make('walkin_description')->label('תיאור (יופיע בחשבונית)')->maxLength(120)->required(),
                        Toggle::make('walkin_vat_exempt')->label('פטור ממע״מ')->helperText('סמנו אם ללקוח אין חבות מע״מ.'),
                    ])->columns(3)
                    ->footerActions([
                        FormAction::make('createChargePage')
                            ->label('צור עמוד תשלום')
                            ->icon('heroicon-o-globe-alt')
                            ->action(fn () => $this->createChargePage()),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Create the one-off charge and queue it for processing. The Cardcom call
     * itself runs in the job (never in this request).
     */
    public function charge(): void
    {
        $data = $this->form->getState();

        $customer = Customer::find($data['customer_id']);

        if (! $customer || ! $customer->paymentTokens()->where('status', TokenStatus::Active)->exists()) {
            Notification::make()->title('ללקוח אין כרטיס פעיל שמור')->danger()->send();

            return;
        }

        $totalAgorot = (int) round(((float) $data['amount']) * 100);

        if ($totalAgorot <= 0) {
            Notification::make()->title('סכום לא תקין')->danger()->send();

            return;
        }

        [$net, $vat] = $this->splitVat($totalAgorot, (bool) $customer->vat_exempt);

        $charge = Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => $data['description'],
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        ProcessManualChargeJob::dispatch($charge->id);

        Notification::make()
            ->title('החיוב נשלח לעיבוד')
            ->body("הלקוח {$customer->name} יחויב בסך ₪".number_format($totalAgorot / 100, 2).'. עקבו אחר התוצאה בעמוד "חיובים" (החיוב וההנפקה בלינט מתבצעים ברקע).')
            ->success()
            ->persistent()
            ->send();

        $this->form->fill(['description' => 'חיוב ידני', 'walkin_description' => 'חיוב חד-פעמי']);
    }

    /**
     * One-off charge for a walk-in customer: create (or reuse) the customer and a
     * pending charge, then open a Cardcom hosted page where the card is entered.
     * The charge is finalised by the webhook (matched on LowProfileId).
     */
    public function createChargePage(): void
    {
        $data = $this->form->getState();

        $name = trim((string) ($data['walkin_name'] ?? ''));
        $totalAgorot = (int) round(((float) ($data['walkin_amount'] ?? 0)) * 100);

        if ($name === '' || $totalAgorot <= 0) {
            Notification::make()->title('יש למלא שם וסכום תקין')->danger()->send();

            return;
        }

        $email = filled($data['walkin_email'] ?? null) ? $data['walkin_email'] : null;
        $vatExempt = (bool) ($data['walkin_vat_exempt'] ?? false);
        $description = filled($data['walkin_description'] ?? null) ? $data['walkin_description'] : 'חיוב חד-פעמי';

        // Reuse an existing customer by email, otherwise create a new one.
        $customer = ($email ? Customer::where('email', $email)->first() : null)
            ?? Customer::create([
                'name' => $name,
                'email' => $email,
                'phone' => $data['walkin_phone'] ?? null,
                'business_number' => $data['walkin_business_number'] ?? null,
                'vat_exempt' => $vatExempt,
            ]);

        [$net, $vat] = $this->splitVat($totalAgorot, $vatExempt);

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
            ->title('עמוד התשלום נוצר')
            ->body('פִּתחו את עמוד התשלום להזנת כרטיס, או העתיקו ושִלחו את הקישור ללקוח: '.$lowProfile['url'])
            ->success()
            ->persistent()
            ->actions([
                NotificationAction::make('open')
                    ->label('פתח עמוד תשלום')
                    ->url($lowProfile['url'], shouldOpenInNewTab: true),
            ])
            ->send();

        $this->form->fill(['description' => 'חיוב ידני', 'walkin_description' => 'חיוב חד-פעמי']);
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
