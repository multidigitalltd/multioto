<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Filament\Support\InstallmentFields;
use App\Filament\Support\MoneyField;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit a customer's subscriptions directly from the customer card — no jumping
 * to a separate screen. Creating a full subscription still goes through the
 * onboarding wizard (it needs a billing period), so this manager is edit-first.
 */
class SubscriptionsRelationManager extends RelationManager
{
    /**
     * Subscriptions are finance-module territory (create/edit/cancel/charge
     * now) — a team member without the finance module must not see or reach
     * this tab even though the customer page itself is in ניהול.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->canAccessModule('finance') ?? false;
    }

    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'מנויים';

    // Keep the table interactive on the customer's view page too (Filament makes
    // relation managers read-only on ViewRecord pages by default).
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('plan_id')->label('תוכנית קבועה')->relationship('plan', 'name')
                ->live()
                ->helperText('בחרו מוצר קבוע, או השאירו ריק למנוי חופשי בהתאמה אישית.'),
            // Free-form fields — for a fully custom subscription with no fixed plan.
            Forms\Components\TextInput::make('name')->label('שם מנוי חופשי')->maxLength(190)
                ->visible(fn (Forms\Get $get): bool => blank($get('plan_id')))
                ->helperText('לדוגמה: אחסון + תחזוקה חודשית'),
            Forms\Components\Group::make([
                // Required for a plan-less subscription — no plan price to fall back on.
                MoneyField::make('price_agorot_override', 'מחיר (₪)')->required(),
                Forms\Components\Select::make('billing_interval')->label('תדירות חיוב')
                    ->options(BillingInterval::class),
                Forms\Components\Toggle::make('vat_applies')->label('הוסף מע״מ')->default(true),
            ])->columns(3)->visible(fn (Forms\Get $get): bool => blank($get('plan_id'))),
            Forms\Components\Select::make('site_id')->label('אתר')
                ->relationship('site', 'domain', fn ($query, RelationManager $livewire) => $query->where('customer_id', $livewire->getOwnerRecord()->id))
                ->helperText('אופציונלי — האתר שהמנוי מכסה.'),
            // How THIS subscription is paid. The customer-level setting stays the
            // default, so the common case needs no decision — but the same
            // customer can have hosting on a standing order and a retainer on
            // the card, and until now one answer was forced onto both.
            Forms\Components\Select::make('payment_method')
                ->label('אמצעי תשלום למנוי')
                ->options(PaymentMethod::options())
                ->placeholder(fn (RelationManager $livewire): string => 'כמו בכרטיס הלקוח'.
                    (filled($method = $livewire->getOwnerRecord()->payment_method)
                        ? ' ('.(PaymentMethod::tryFrom($method)?->getLabel() ?? $method).')'
                        : ''))
                ->live()
                ->helperText('רק במנוי שמשלמים אחרת מברירת המחדל של הלקוח. מנוי שמוגדר כאן לגבייה ידנית לא ייגבה אוטומטית בכרטיס, גם אם יש כרטיס שמור.'),
            Forms\Components\TextInput::make('card_fallback_days')
                ->label('כרטיס גיבוי אחרי (ימים)')
                ->numeric()->minValue(1)->maxValue(120)
                ->placeholder('ללא — לא לחייב בכרטיס')
                ->visible(fn (Forms\Get $get, RelationManager $livewire): bool => PaymentMethod::isManualValue(
                    $get('payment_method') ?: $livewire->getOwnerRecord()->payment_method,
                ))
                ->helperText('אם התשלום לא סומן כשולם תוך כך וכך ימים מהמועד — הכרטיס השמור יחויב אוטומטית, והצוות יקבל התראה. השאירו ריק כדי שהכרטיס לעולם לא יחויב על המנוי הזה.'),
            Forms\Components\Select::make('status')->label('סטטוס')->options(SubscriptionStatus::class)
                ->default(SubscriptionStatus::Active)->required(),
            Forms\Components\DateTimePicker::make('next_charge_at')->label('חיוב הבא'),
            // פריסת תשלומים — אותם שדות בדיוק כמו בטופס המנוי המלא.
            ...InstallmentFields::schema(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('plan_name')->label('תוכנית')
                    ->state(fn (Subscription $record): string => $record->planName())
                    ->description(fn (Subscription $record): ?string => $record->installmentSummary()),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                // Says how the money actually arrives, and whether the card is
                // standing behind it — the question the whole setting exists to
                // answer, so it belongs in the list and not only in the form.
                Tables\Columns\TextColumn::make('payment_method')->label('תשלום')
                    ->state(fn (Subscription $record): string => $record->collectsAutomatically()
                        ? 'כרטיס — אוטומטי'
                        : (PaymentMethod::tryFrom((string) $record->effectivePaymentMethod())?->getLabel() ?? 'כרטיס'))
                    ->badge()
                    ->color(fn (Subscription $record): string => $record->collectsAutomatically() ? 'success' : 'warning')
                    ->description(fn (Subscription $record): ?string => $record->usesCardFallback()
                        ? 'כרטיס גיבוי אחרי '.$record->card_fallback_days.' ימים'
                        : null),
                Tables\Columns\TextColumn::make('next_charge_at')->label('חיוב הבא')->dateTime('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('dunning_stage')->label('שלב דאנינג')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('מנוי חדש')
                    ->mutateFormDataUsing(function (array $data): array {
                        // Give a new subscription a sensible first period.
                        $data['current_period_start'] ??= now()->toDateString();
                        $data['current_period_end'] ??= now()->addMonth()->toDateString();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('chargeNow')
                    ->label('חייב עכשיו')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (Subscription $record): bool => $record->isChargeable())
                    ->requiresConfirmation()
                    ->action(function (Subscription $record): void {
                        // Collect now without moving the billing anchor forward
                        // (a late payer must not earn free days).
                        $record->markDueNow();
                        ChargeSubscriptionJob::dispatch($record->id, manual: true);
                        Notification::make()->title('החיוב נשלח לביצוע')->success()->send();
                    }),
                Tables\Actions\EditAction::make()->label('עריכה'),
                // Cancel keeps the subscription (and its billing history) but stops
                // future charges — the usual way to end a subscription.
                Tables\Actions\Action::make('cancel')
                    ->label('ביטול מנוי')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Canceled)
                    ->requiresConfirmation()
                    ->modalHeading('ביטול מנוי')
                    ->modalDescription('המנוי יבוטל ולא יחויב יותר. ההיסטוריה והחיובים נשמרים. אפשר להפעיל מחדש דרך עריכה.')
                    ->action(function (Subscription $record): void {
                        $record->cancel();
                        Notification::make()->title('המנוי בוטל')->success()->send();
                    }),
                // Delete removes the record entirely — for a subscription opened by
                // mistake. Cancel is preferred for a real subscription.
                Tables\Actions\DeleteAction::make()
                    ->label('מחיקה')
                    ->modalDescription('מחיקה מוחלטת של המנוי. לביטול מנוי פעיל עדיף להשתמש ב״ביטול מנוי״ כדי לשמור היסטוריה.'),
            ]);
    }
}
