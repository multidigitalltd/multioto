<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

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
        $this->form->fill(['description' => 'חיוב ידני']);
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
                                ->whereHas('paymentTokens')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('מוצגים רק לקוחות עם כרטיס שמור.'),
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

        if (! $customer || ! $customer->paymentTokens()->exists()) {
            Notification::make()->title('ללקוח אין כרטיס שמור')->danger()->send();

            return;
        }

        $totalAgorot = (int) round(((float) $data['amount']) * 100);

        if ($totalAgorot <= 0) {
            Notification::make()->title('סכום לא תקין')->danger()->send();

            return;
        }

        // Split the total into net + VAT for the invoice. Exempt customers pay
        // no VAT, so the whole amount is net.
        if ($customer->vat_exempt) {
            $net = $totalAgorot;
            $vat = 0;
        } else {
            $vatRate = (float) config('billing.vat_rate');
            $net = (int) round($totalAgorot / (1 + $vatRate));
            $vat = $totalAgorot - $net;
        }

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

        $this->form->fill(['description' => 'חיוב ידני']);
    }
}
