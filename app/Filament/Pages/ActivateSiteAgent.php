<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Jobs\SendCardCaptureLinkJob;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\WhatsAppCloudClient;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * הפעלת סוכן האתר — מסך אחד שפותח את המנוי ומחבר את המספר.
 *
 * Selling this product used to mean three screens in the right order: open a
 * subscription on a plan whose agent flag is set, add the number, send it a
 * code. Get the order wrong, or the plan wrong, and the result is a number that
 * answers "אין מנוי פעיל" to a customer who just paid — or, worse, a number
 * driving a site with nothing billing for it.
 *
 * So it is one screen and one transaction. The customer is never chosen: it is
 * the site's owner, read from the site, because a subscription opened for one
 * customer and a number bound to another is exactly the pair of rows that makes
 * the entitlement check read the wrong person's subscription.
 */
class ActivateSiteAgent extends Page implements HasForms
{
    use InteractsWithForms;
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'הפעלת סוכן לאתר';

    protected static ?string $title = 'הפעלת סוכן וואטסאפ לניהול אתר';

    protected static string $view = 'filament.pages.activate-site-agent';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Per-request memos.
     *
     * Every one of these is read by two or three closures that Livewire
     * re-evaluates on each round trip — the select's options and its helper
     * text, the customer line and the price line. Without them, typing a price
     * re-queries the sites, the plans and the customer on every keystroke.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function mount(): void
    {
        $this->form->fill([
            'first_charge_at' => now()->format('Y-m-d'),
            'send_code' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('האתר')
                    ->description('רק אתרים שהתוסף מחובר בהם. בלי חיבור לסוכן אין ידיים, והמנוי היה נפתח על שירות שאינו יכול לפעול.')
                    ->schema([
                        Select::make('site_id')
                            ->label('אתר')
                            ->options(fn (): array => $this->connectedSites())
                            ->searchable()
                            ->required()
                            ->live()
                            ->helperText(fn (): ?string => $this->connectedSites() === []
                                ? 'אין כרגע אתר מחובר לתוסף. חברו את האתר ואז חזרו לכאן.'
                                : null),
                        Placeholder::make('customer_state')
                            ->label('הלקוח')
                            ->content(fn (Get $get): string => $this->customerState($get)),
                    ])->columns(2),

                Section::make('המנוי')
                    ->description('התשלום החודשי על השירות. המסלול חייב להיות מסומן כ"כולל סוכן ניהול אתר" — זה מה שמפעיל את הסוכן בפועל.')
                    ->schema([
                        Select::make('plan_id')
                            ->label('מסלול')
                            ->options(fn (): array => $this->agentPlans())
                            ->required()
                            ->live()
                            ->helperText(fn (): ?string => $this->agentPlans() === []
                                ? 'אין מסלול שמסומן כ"כולל סוכן ניהול אתר". צרו אותו במסך המסלולים ואז חזרו לכאן.'
                                : null),
                        TextInput::make('price_override')
                            ->label('מחיר מיוחד (₪, אופציונלי)')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->helperText('רק אם סוכם מחיר שונה מהמסלול.'),
                        DatePicker::make('first_charge_at')
                            ->label('תאריך חיוב ראשון')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Placeholder::make('summary')
                            ->label('סיכום')
                            ->content(fn (Get $get): string => $this->summary($get)),
                    ])->columns(2),

                Section::make('המספר שינהל')
                    ->description('המספר יקבל קוד בן 6 ספרות וישלח אותו חזרה. עד שיאומת אינו יכול לעשות דבר.')
                    ->schema([
                        TextInput::make('phone')
                            ->label('מספר וואטסאפ')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->helperText('אפשר להקליד 050-1234567 — יומר לפורמט הבינלאומי אוטומטית.'),
                        TextInput::make('name')
                            ->label('שם')
                            ->maxLength(120)
                            ->helperText('אופציונלי — יתמלא מהפרופיל בוואטסאפ אם ריק.'),
                        Toggle::make('send_code')
                            ->label('שלח קוד אימות עכשיו')
                            ->inline(false)
                            ->helperText('אפשר לכבות ולשלוח בהמשך ממסך מנויי הסוכן.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    /** Sites the plugin is actually connected to, labelled with their owner. */
    private function connectedSites(): array
    {
        return $this->memo['sites'] ??= Site::query()
            ->select(['id', 'domain', 'customer_id'])
            ->where('mcp_enabled', true)
            ->whereNotNull('mcp_endpoint')
            ->whereNotNull('customer_id')
            ->with('customer:id,name')
            ->orderBy('domain')
            ->get()
            ->mapWithKeys(fn (Site $site): array => [
                $site->id => $site->domain.' — '.($site->customer?->name ?? ''),
            ])
            ->all();
    }

    /** Active plans whose flag is what actually switches the agent on. */
    private function agentPlans(): array
    {
        return $this->memo['plans'] ??= Plan::query()
            ->where('active', true)
            ->where('includes_site_agent', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * What the team needs to know about this customer before opening a
     * subscription for them: who they are, whether a card can be charged, and
     * whether they are already paying for the agent.
     */
    private function customerState(Get $get): string
    {
        $site = $this->site($get);

        if ($site?->customer === null) {
            return 'בחרו אתר.';
        }

        $customer = $site->customer;

        $live = app(SiteAgentBilling::class)->subscriptionFor($customer);

        if (in_array($live?->status, SiteAgentAccess::ENTITLING, true)) {
            return $customer->name.' — כבר יש מנוי פעיל לסוכן. המספר החדש יצורף אליו, בלי לפתוח מנוי שני.';
        }

        if (in_array($live?->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
            return $customer->name.' — יש מנוי קיים לסוכן בפיגור תשלום. המספר יצורף אליו; '
                .'הסוכן יענה רק אחרי שהחוב יוסדר.';
        }

        return $customer->name.' — '.($customer->hasActiveCard()
            ? 'יש כרטיס אשראי בתוקף, החיוב יתחיל בתאריך שנבחר.'
            : 'אין כרטיס אשראי בתוקף. יישלח ללקוח קישור להזנת כרטיס, והחיוב יתחיל כשהכרטיס יוזן.');
    }

    /** The money, said once, the way it will appear on the charge. */
    private function summary(Get $get): string
    {
        $plan = filled($get('plan_id')) ? Plan::find($get('plan_id')) : null;

        if ($plan === null) {
            return 'בחרו מסלול.';
        }

        $base = filled($get('price_override'))
            ? (int) round(((float) $get('price_override')) * 100)
            : (int) $plan->price_agorot;

        $exempt = (bool) $this->site($get)?->customer?->vat_exempt;

        $vat = ($exempt || ! $plan->vat_applies)
            ? 0
            : (int) round($base * config('billing.vat_rate'));

        return $plan->name.' · '.Money::ils($base + $vat).' לחיוב'.($vat > 0 ? ' (כולל מע״מ)' : ' (ללא מע״מ)');
    }

    private function site(Get $get): ?Site
    {
        if (blank($get('site_id'))) {
            return null;
        }

        // Keyed by id: switching the selection must re-read, and the two
        // placeholders that both need it must not read twice.
        return $this->memo['site:'.$get('site_id')] ??= Site::with('customer')->find($get('site_id'));
    }

    /**
     * Open the subscription and bind the number, in one transaction.
     *
     * Everything the form showed is re-checked here against the database. A
     * screen that was open while somebody disconnected the site, or unticked
     * the plan's agent flag, would otherwise sell a subscription that cannot
     * work — and the customer would be the one to discover it.
     */
    public function activate(): void
    {
        $data = $this->form->getState();

        $site = Site::with('customer')->find($data['site_id']);
        $plan = Plan::find($data['plan_id']);

        if ($site?->customer === null || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            Notification::make()->title('האתר אינו מחובר לתוסף')
                ->body('בלי חיבור הסוכן אינו יכול לפעול, ולכן אין מה להפעיל עליו מנוי.')
                ->danger()->send();

            return;
        }

        if ($plan === null || ! $plan->includes_site_agent || ! $plan->active) {
            Notification::make()->title('המסלול אינו כולל את סוכן האתר')
                ->body('רק מסלול פעיל שמסומן "כולל סוכן ניהול אתר" מפעיל את השירות.')
                ->danger()->send();

            return;
        }

        $customer = $site->customer;
        $phone = app(WhatsAppCloudClient::class)->normalize((string) $data['phone']);

        if ($phone === '') {
            Notification::make()->title('מספר הוואטסאפ אינו תקין')->danger()->send();

            return;
        }

        $existing = app(SiteAgentBilling::class)->subscriptionFor($customer);

        // A second manager's number is not a second subscription, and neither is
        // re-activating a customer who fell behind. The entitlement is the
        // customer's: any agent subscription that has not been closed is THE
        // one, and opening another would bill the same service twice — once on
        // each, with the old one still being chased for the arrears.
        $reuse = $existing !== null && $existing->status !== SubscriptionStatus::Canceled;

        [$subscription, $subscriber] = DB::transaction(function () use ($data, $site, $customer, $plan, $phone, $reuse, $existing): array {
            $subscription = $reuse ? $existing : Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'site_id' => $site->id,
                // A card on file means this collects itself on the chosen date.
                // Without one there is nothing to charge, and Trialing is what
                // keeps it out of the charge run until the card arrives — at
                // which point CardTokenService converts it to Active.
                'status' => $customer->hasActiveCard()
                    ? SubscriptionStatus::Active
                    : SubscriptionStatus::Trialing,
                'price_agorot_override' => filled($data['price_override'] ?? null)
                    ? (int) round(((float) $data['price_override']) * 100)
                    : null,
                'next_charge_at' => $data['first_charge_at'],
            ]);

            // The same number may already be bound to this site — a manager who
            // was revoked and is coming back, or a second attempt after a code
            // that never arrived. Reuse the row rather than colliding with the
            // unique key, and clear the revocation as part of re-granting it.
            $subscriber = SiteAgentSubscriber::firstOrNew([
                'phone' => $phone,
                'site_id' => $site->id,
            ]);

            $subscriber->fill([
                'customer_id' => $customer->id,
                'name' => filled($data['name'] ?? null) ? $data['name'] : $subscriber->name,
            ])->forceFill([
                'revoked_at' => null,
                'revoked_reason' => null,
            ])->save();

            return [$subscription, $subscriber];
        });

        // Outside the transaction: nothing external may run before the rows it
        // talks about are committed.
        if ($data['send_code'] ?? false) {
            SendSiteAgentVerificationJob::dispatch($subscriber->id);
        }

        if ($subscription->status === SubscriptionStatus::Trialing) {
            SendCardCaptureLinkJob::dispatch($subscription->id);
        }

        Notification::make()
            ->title($reuse ? 'המספר צורף למנוי הקיים' : 'המנוי נפתח והסוכן מוכן')
            ->body(implode(' ', array_filter([
                $reuse ? 'ללקוח כבר יש מנוי לסוכן, ולכן לא נפתח מנוי נוסף.' : null,
                // Said out loud rather than left to be discovered: the number is
                // bound, and the agent will still refuse it until the arrears
                // are settled.
                in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)
                    ? 'שימו לב: המנוי הקיים בפיגור תשלום — הסוכן לא יענה עד שיוסדר.'
                    : null,
                $subscription->status === SubscriptionStatus::Trialing
                    ? 'נשלח ללקוח קישור להזנת כרטיס — החיוב יתחיל כשהכרטיס יוזן.'
                    : null,
                ($data['send_code'] ?? false)
                    ? 'קוד האימות נשלח למספר; הלקוח צריך לשלוח אותו חזרה באותה שיחה.'
                    : 'לא נשלח קוד אימות — אפשר לשלוח ממסך מנויי הסוכן.',
            ])))
            ->success()
            ->send();

        $this->form->fill([
            'first_charge_at' => now()->format('Y-m-d'),
            'send_code' => true,
        ]);
    }
}
