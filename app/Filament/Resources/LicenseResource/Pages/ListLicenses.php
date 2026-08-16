<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use App\Models\Customer;
use App\Models\PluginPlan;
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
                    Forms\Components\Toggle::make('includes_updates')
                        ->label('כולל עדכונים')
                        ->default(true)
                        // The one thing a date cannot express: a licence bought
                        // outright without updates is valid forever and never
                        // offered a newer version.
                        ->helperText('כבוי = הלקוח מקבל את התוסף בגרסה הנוכחית לתמיד, בלי גרסאות חדשות. הרישיון יישאר "פעיל" ולא יפוג.'),
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
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('plugin_plan_id', null)),
                Forms\Components\Select::make('plugin_plan_id')
                    ->label('מסלול')
                    ->required()->native(false)->live()
                    // Named with its price and what happens to updates, because
                    // those are the two things being decided here — a list of
                    // bare plan names would make this a guess.
                    ->options(fn (Forms\Get $get): array => PluginPlan::query()
                        ->where('plugin_product_id', $get('plugin_product_id'))
                        ->where('is_active', true)
                        ->orderBy('position')
                        ->get()
                        ->mapWithKeys(fn (PluginPlan $plan): array => [
                            $plan->id => $plan->name.' — '.$plan->priceLabel().' · '.$plan->sitesLabel().' · '.$plan->updatesLabel(),
                        ])
                        ->all())
                    ->helperText('המחיר, מספר האתרים והעדכונים נגזרים מהמסלול. אפשר לשנות אותם למכירה הזו בלבד למטה.'),
                Forms\Components\Select::make('customer_id')
                    ->label('לקוח')
                    ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()->searchable()->native(false),
                Forms\Components\TextInput::make('price_agorot')
                    ->label('מחיר לתקופה (אופציונלי)')->numeric()->minValue(0)->prefix('אגורות')
                    ->placeholder(fn (Forms\Get $get): ?string => (string) (PluginPlan::find($get('plugin_plan_id'))?->price_agorot ?? ''))
                    ->helperText('ריק = מחיר המסלול. מלאו רק כשסוכם מחיר אחר עם הלקוח הזה.'),
                Forms\Components\TextInput::make('sites_limit')
                    ->label('מספר אתרים (אופציונלי)')->numeric()->minValue(0)
                    ->placeholder(fn (Forms\Get $get): ?string => (string) (PluginPlan::find($get('plugin_plan_id'))?->sites_limit ?? ''))
                    ->helperText('ריק = כמו במסלול. 0 = ללא הגבלה.'),
                Forms\Components\Textarea::make('notes')->label('הערות פנימיות')->rows(2),
            ])
            ->action(function (array $data): void {
                $plan = PluginPlan::findOrFail($data['plugin_plan_id']);
                $customer = Customer::findOrFail($data['customer_id']);

                $sale = app(LicenseSale::class)->sell(
                    plan: $plan,
                    customer: $customer,
                    sitesLimit: filled($data['sites_limit'] ?? null) ? (int) $data['sites_limit'] : null,
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
