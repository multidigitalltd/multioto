<?php

namespace App\Filament\Pages;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendCardCaptureLinkJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * לקוח חדש — אשף אחד שמקים לקוח + אתר + מנוי במסך אחד, ובסיום שולח ללקוח
 * קישור מאובטח להזנת כרטיס אשראי. מחליף מילוי של שלושה מסכים נפרדים.
 *
 * A single onboarding wizard: creates the Customer, Site and Subscription in
 * one transaction, then (optionally) queues a card-capture invite. Everything
 * heavy/external happens in the queued job, not in this request.
 */
class OnboardCustomer extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = -10;

    protected static ?string $navigationLabel = 'לקוח חדש';

    protected static ?string $title = 'הקמת לקוח חדש';

    protected static string $view = 'filament.pages.onboard-customer';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'business_type' => BusinessType::LicensedDealer->value,
            'vat_exempt' => false,
            'monitor_enabled' => true,
            'send_card_link' => true,
            'first_charge_at' => now()->format('Y-m-d'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Step::make('הלקוח')
                        ->icon('heroicon-o-user')
                        ->description('פרטי העסק וההתקשרות')
                        ->schema([
                            TextInput::make('name')->label('שם הלקוח / העסק')->required()->maxLength(255),
                            TextInput::make('phone')->label('טלפון / וואטסאפ')->tel()->required()
                                ->helperText('לכאן יישלח הקישור להזנת כרטיס אשראי'),
                            TextInput::make('email')->label('אימייל')->email()->required(),
                            TextInput::make('business_number')->label('ח.פ / עוסק')->maxLength(20),
                            Select::make('business_type')->label('סוג עסק')
                                ->options(collect(BusinessType::cases())->mapWithKeys(
                                    fn (BusinessType $t) => [$t->value => $t->getLabel()]
                                ))->required(),
                            Toggle::make('vat_exempt')->label('פטור ממע״מ')
                                ->helperText('חשבוניות יונפקו ללא מע״מ'),
                        ])->columns(2),

                    Step::make('האתר')
                        ->icon('heroicon-o-globe-alt')
                        ->description('הדומיין והניטור')
                        ->schema([
                            TextInput::make('domain')->label('דומיין')->required()
                                ->placeholder('example.co.il')->maxLength(255),
                            TextInput::make('monitor_url')->label('כתובת לניטור')->url()
                                ->placeholder('https://example.co.il')
                                ->helperText('אם ריק — נשתמש בדומיין. בדיקת "האתר למטה" רצה אוטומטית'),
                            Toggle::make('monitor_enabled')->label('הפעל ניטור זמינות')->default(true),
                        ])->columns(2),

                    Step::make('המנוי')
                        ->icon('heroicon-o-credit-card')
                        ->description('התוכנית והחיוב')
                        ->schema([
                            Select::make('plan_id')->label('תוכנית')
                                ->options(Plan::where('active', true)->pluck('name', 'id'))
                                ->required()
                                ->live()
                                ->helperText('מחיר ותדירות נקבעים לפי התוכנית'),
                            TextInput::make('price_override')->label('מחיר מיוחד (₪, אופציונלי)')
                                ->numeric()->minValue(0)
                                ->helperText('רק אם סוכם מחיר שונה מהתוכנית'),
                            DatePicker::make('first_charge_at')->label('תאריך חיוב ראשון')
                                ->required()->native(false)->displayFormat('d/m/Y'),
                            Toggle::make('send_card_link')->label('שלח ללקוח קישור להזנת כרטיס')
                                ->default(true)
                                ->helperText('הלקוח יזין את הכרטיס בעצמו בעמוד המאובטח של חברת הסליקה'),
                            Placeholder::make('summary')->label('סיכום')
                                ->content(fn (Get $get): string => $this->summaryText($get)),
                        ])->columns(2),
                ])
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit" size="lg">הקמת הלקוח</x-filament::button>'
                    ))),
            ])
            ->statePath('data');
    }

    protected function summaryText(Get $get): string
    {
        $plan = $get('plan_id') ? Plan::find($get('plan_id')) : null;

        if (! $plan) {
            return 'בחרו תוכנית כדי לראות את סכום החיוב.';
        }

        $agorot = filled($get('price_override'))
            ? (int) round(((float) $get('price_override')) * 100)
            : $plan->price_agorot;

        $vat = $get('vat_exempt') || ! $plan->vat_applies
            ? 0
            : (int) round($agorot * config('billing.vat_rate'));

        $total = number_format(($agorot + $vat) / 100, 2);

        return "המנוי {$plan->name} · סה״כ לחיוב: {$total} ₪ (כולל מע״מ).";
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $subscription = DB::transaction(function () use ($data): Subscription {
            $customer = Customer::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'business_number' => $data['business_number'] ?? null,
                'business_type' => $data['business_type'],
                'vat_exempt' => (bool) ($data['vat_exempt'] ?? false),
                'status' => CustomerStatus::Active,
            ]);

            $site = Site::create([
                'customer_id' => $customer->id,
                'domain' => $data['domain'],
                'monitor_url' => $data['monitor_url'] ?: 'https://'.$data['domain'],
                'monitor_enabled' => (bool) ($data['monitor_enabled'] ?? true),
                'status' => SiteStatus::Active,
            ]);

            return Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $data['plan_id'],
                'site_id' => $site->id,
                // No token yet — the customer enters their card via the link.
                // Trialing keeps it out of the charge run until a card exists.
                'status' => SubscriptionStatus::Trialing,
                'price_agorot_override' => filled($data['price_override'] ?? null)
                    ? (int) round(((float) $data['price_override']) * 100)
                    : null,
                'next_charge_at' => $data['first_charge_at'],
            ]);
        });

        if ($data['send_card_link'] ?? false) {
            SendCardCaptureLinkJob::dispatch($subscription->id);
        }

        Notification::make()
            ->title('הלקוח הוקם בהצלחה')
            ->body(($data['send_card_link'] ?? false)
                ? 'נשלח ללקוח קישור להזנת כרטיס אשראי. המנוי יופעל אוטומטית לאחר שהכרטיס יוזן.'
                : 'המנוי נוצר. יש להזין כרטיס אשראי כדי להתחיל בחיוב.')
            ->success()
            ->send();

        $this->form->fill([
            'business_type' => BusinessType::LicensedDealer->value,
            'vat_exempt' => false,
            'monitor_enabled' => true,
            'send_card_link' => true,
            'first_charge_at' => now()->format('Y-m-d'),
        ]);
    }
}
