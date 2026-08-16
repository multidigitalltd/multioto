<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Enums\BillingInterval;
use App\Filament\Resources\LicenseResource;
use App\Models\Customer;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Services\Licensing\LicenseIssuer;
use App\Services\Licensing\LicenseSale;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    /**
     * Issuing is its own action rather than the ordinary "create", because it
     * has an outcome an ordinary create does not: a key comes into existence,
     * is shown once, and can never be read again. A form that saved a row and
     * returned to the list would lose it.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->sellAction(),
            Actions\Action::make('issue')
                ->label('הנפקת רישיון')
                ->icon('heroicon-o-key')
                ->modalHeading('הנפקת רישיון חדש')
                ->modalDescription('המפתח ייווצר עכשיו, יישלח ללקוח במייל ויוצג כאן פעם אחת. הוא אינו נשמר אצלנו ולא ניתן יהיה להציג אותו שוב.')
                ->modalSubmitActionLabel('הנפק ושלח')
                ->form([
                    Forms\Components\Select::make('plugin_product_id')
                        ->label('תוסף')
                        ->options(fn (): array => PluginProduct::query()->where('is_active', true)->pluck('name', 'id')->all())
                        ->required()->native(false),
                    Forms\Components\Select::make('customer_id')
                        ->label('לקוח')
                        ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->native(false)
                        ->live()
                        // Filling the address from the customer saves a step and,
                        // more importantly, stops the key being sent to a typo.
                        ->afterStateUpdated(function (Forms\Set $set, $state): void {
                            $set('email', Customer::find($state)?->email);
                        }),
                    Forms\Components\TextInput::make('email')
                        ->label('אימייל לשליחת המפתח')
                        ->email()
                        ->helperText('בלי כתובת הרישיון ייווצר, אך המפתח יוצג כאן בלבד — העתיקו אותו לפני הסגירה.'),
                    Forms\Components\TextInput::make('sites_limit')
                        ->label('מספר אתרים')->numeric()->minValue(0)->default(1)->required()
                        ->helperText('0 = ללא הגבלה.'),
                    Forms\Components\DatePicker::make('expires_at')
                        ->label('בתוקף עד')
                        ->default(now()->addYear())
                        ->helperText('ריק = ללא תפוגה. רישיון שנתי = שנה מהיום; חודשי = חודש.'),
                    Forms\Components\Select::make('subscription_id')
                        ->label('מנוי שמחדש אוטומטית')
                        ->options(fn (): array => Subscription::query()
                            ->with('customer')
                            ->get()
                            ->mapWithKeys(fn ($s): array => [$s->id => $s->name.' — '.($s->customer?->name ?? '')])
                            ->all())
                        ->searchable()->native(false)
                        ->helperText('כשחיוב של המנוי מצליח, התוקף נדחה לסוף התקופה ששולמה. בלי מנוי — החידוש ידני.'),
                    Forms\Components\Textarea::make('notes')->label('הערות פנימיות')->rows(2),
                ])
                ->action(function (array $data): void {
                    [$license, $key, $sent] = app(LicenseIssuer::class)->issue($data);

                    // Persistent on purpose: this notification is the only place
                    // the key will ever appear on a screen. A toast that fades
                    // after four seconds would take it with it.
                    Notification::make()
                        ->title('הרישיון הונפק — '.$key)
                        ->body($sent
                            ? 'המפתח נשלח ל'.$license->email.'. הוא אינו נשמר אצלנו ולא ניתן להציג אותו שוב.'
                            : 'לא נשלח מייל (אין כתובת על הרישיון). העתיקו את המפתח עכשיו — הוא אינו נשמר ולא ניתן להציגו שוב.')
                        ->{$sent ? 'success' : 'warning'}()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /**
     * Selling a licence: the money and the key, in one step.
     *
     * Separate from "issue" because they answer different questions. Issuing is
     * for a licence somebody already paid for, or was given; selling opens the
     * subscription that will collect for it and keep collecting. Doing both from
     * one form would mean every free or comped licence passing through a price
     * field, and every sale through a form that never mentions money.
     */
    private function sellAction(): Actions\Action
    {
        return Actions\Action::make('sell')
            ->label('מכירת רישיון')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading('מכירת רישיון ללקוח')
            ->modalDescription('נפתח מנוי (ברישיון מתחדש), יונפק מפתח והוא יישלח ללקוח. הרישיון עובד מיד; החיוב הראשון ייגבה בהרצת הגבייה הקרובה — תוך רבע שעה.')
            ->modalSubmitActionLabel('מכור והנפק')
            ->form([
                Forms\Components\Select::make('plugin_product_id')
                    ->label('תוסף')
                    ->options(fn (): array => PluginProduct::query()->where('is_active', true)->pluck('name', 'id')->all())
                    ->required()->native(false)->live()
                    // The product's own price and term are the starting point,
                    // so the common sale is three clicks and no typing.
                    ->afterStateUpdated(function (Forms\Set $set, $state): void {
                        $product = PluginProduct::find($state);

                        $set('price_agorot', $product?->price_agorot);
                        $set('billing_interval', $product?->billing_interval);
                        $set('sites_limit', $product?->default_sites_limit ?? 1);
                    }),
                Forms\Components\Select::make('customer_id')
                    ->label('לקוח')
                    ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()->searchable()->native(false),
                Forms\Components\Select::make('billing_interval')
                    ->label('סוג הרישיון')
                    ->native(false)
                    ->options(['yearly' => 'שנתי (מתחדש)', 'monthly' => 'חודשי (מתחדש)'])
                    ->placeholder('חד-פעמי (ללא חידוש)')
                    ->helperText('מתחדש = נפתח מנוי, והחידוש והגבייה מתנהלים כמו כל מנוי אחר. חד-פעמי = לא נפתח מנוי, והחיוב נעשה ממסך החיוב הידני.'),
                Forms\Components\TextInput::make('price_agorot')
                    ->label('מחיר לתקופה')->numeric()->minValue(0)->prefix('אגורות')
                    ->helperText('באגורות, לפני מע״מ.'),
                Forms\Components\TextInput::make('sites_limit')
                    ->label('מספר אתרים')->numeric()->minValue(0)->default(1)->required()
                    ->helperText('0 = ללא הגבלה.'),
                Forms\Components\Textarea::make('notes')->label('הערות פנימיות')->rows(2),
            ])
            ->action(function (array $data): void {
                $product = PluginProduct::findOrFail($data['plugin_product_id']);
                $customer = Customer::findOrFail($data['customer_id']);
                $interval = filled($data['billing_interval'] ?? null)
                    ? BillingInterval::from($data['billing_interval'])
                    : null;

                $sale = app(LicenseSale::class)->sell(
                    product: $product,
                    customer: $customer,
                    sitesLimit: (int) $data['sites_limit'],
                    interval: $interval,
                    priceAgorot: filled($data['price_agorot'] ?? null) ? (int) $data['price_agorot'] : null,
                    notes: $data['notes'] ?? null,
                );

                // What was sold, what happens to the money, and the key itself —
                // which will not be shown again anywhere.
                Notification::make()
                    ->title('נמכר רישיון — '.$sale['key'])
                    ->body(collect([
                        $sale['emailed']
                            ? 'המפתח נשלח ל'.$sale['license']->email.'.'
                            : 'לא נשלח מייל (אין כתובת ללקוח) — העתיקו את המפתח עכשיו.',
                        $sale['subscription'] !== null
                            ? 'נפתח מנוי; החיוב הראשון ייגבה בהרצת הגבייה הקרובה. אם אין כרטיס שמור, שלחו ללקוח קישור להזנת כרטיס.'
                            : 'מכירה חד-פעמית — לא נפתח מנוי. גבו את התשלום ממסך החיוב הידני.',
                        'המפתח אינו נשמר אצלנו ולא ניתן להציג אותו שוב.',
                    ])->implode(' '))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
