<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use App\Models\Customer;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Services\Licensing\LicenseIssuer;
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
}
