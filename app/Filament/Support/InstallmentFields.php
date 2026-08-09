<?php

namespace App\Filament\Support;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\InstallmentSplit;
use App\Support\Money;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * שדות פריסת התשלומים, במקום אחד — הם מופיעים גם בטופס המנוי המלא וגם בכרטיס
 * הלקוח, ושני עותקים של חישוב כסף נפרדים זה מזה בדיוק ביום שבו זה יקר.
 *
 * שני כיוונים לאותה עסקה:
 *  · יודעים כמה בחודש → כותבים מחיר ומספר תשלומים, והתצוגה אומרת מה הסך הכל;
 *  · יודעים כמה בסך הכל → פותחים את המחשבון, מזינים סכום ומספר תשלומים,
 *    והוא ממלא את המחיר החודשי.
 */
class InstallmentFields
{
    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\TextInput::make('installments_total')
                ->label('מספר תשלומים (פריסת חוב)')
                ->numeric()
                ->minValue(1)
                ->maxValue(120)
                ->live(onBlur: true)
                ->helperText('ריק = מנוי מתמשך רגיל, בלי סוף. מספר = פריסה שנסגרת מעצמה אחרי התשלום האחרון, כך שאי אפשר לגבות תשלום נוסף.'),
            Forms\Components\Placeholder::make('installments_summary')
                ->label('סך הפריסה')
                ->content(fn (Forms\Get $get, ?Subscription $record): string => self::preview($get, $record))
                ->visible(fn (Forms\Get $get): bool => filled($get('installments_total'))),
            Forms\Components\Actions::make([self::calculator()])->columnSpanFull(),
        ];
    }

    /**
     * המחשבון ההפוך: סכום כולל ומספר תשלומים → המחיר לתשלום.
     *
     * ממלא את השדות ואינו שומר דבר בעצמו — מי שפותח פריסה עדיין רואה את המספר
     * שנכנס לטופס ומאשר אותו, ואם החלוקה אינה יוצאת עגולה נאמר בדיוק כמה ייגבה
     * בפועל. סכום שאינו מתחלק אינו שגיאה, אבל הוא כן משהו שצריך לדעת עליו לפני
     * ולא אחרי.
     */
    public static function calculator(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('splitTotal')
            ->label('חישוב מסכום כולל')
            ->icon('heroicon-o-calculator')
            ->color('gray')
            ->modalHeading('פריסת סכום לתשלומים')
            ->modalDescription('הזינו את הסכום שסוכם עם הלקוח ואת מספר התשלומים — המחיר החודשי יחושב וייכנס לטופס.')
            ->modalSubmitActionLabel('חשב ומלא')
            ->form([
                Forms\Components\TextInput::make('total')
                    ->label('סכום כולל לגבייה (₪)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->live(onBlur: true),
                Forms\Components\TextInput::make('count')
                    ->label('מספר תשלומים')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(120)
                    ->required()
                    ->live(onBlur: true),
                Forms\Components\Toggle::make('includes_vat')
                    ->label('הסכום שהוזן כולל מע״מ')
                    ->default(true)
                    ->live()
                    ->helperText('כמעט תמיד כן — זה הסכום שהלקוח אמור לשלם בפועל. המחיר שנשמר על המנוי הוא לפני מע״מ, והמערכת מחלצת אותו.'),
            ])
            ->action(function (array $data, Forms\Get $get, Forms\Set $set, ?Subscription $record): void {
                $count = (int) ($data['count'] ?? 0);
                $totalAgorot = (int) round(((float) ($data['total'] ?? 0)) * 100);

                $split = InstallmentSplit::compute(
                    $totalAgorot,
                    $count,
                    self::vatRateFor($get, $record),
                    (bool) ($data['includes_vat'] ?? true),
                );

                if ($split['per_charge_agorot'] < 1) {
                    Notification::make()->warning()->title('לא ניתן לחשב')
                        ->body('בדקו את הסכום ואת מספר התשלומים.')->send();

                    return;
                }

                // המחיר נשמר לפני מע״מ — זה מה שהשדה בטופס מחזיק.
                $set('price_agorot_override', number_format($split['base_agorot'] / 100, 2, '.', ''));
                $set('installments_total', $count);

                Notification::make()
                    ->{$split['difference_agorot'] === 0 ? 'success' : 'warning'}()
                    ->title('המחיר החודשי חושב: '.Money::ils($split['per_charge_agorot']))
                    ->body(InstallmentSplit::describe($split, $count))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * מה באמת ייגבה, לפי מצב הטופס — כולל מע״מ, ולפי אותו חשבון של החיוב עצמו.
     *
     * תצוגה שמכפילה את המחיר לפני מע״מ מבטיחה סכום שאינו הסכום שייגבה, ומי
     * שמסתמך עליה מסכם עם הלקוח מספר אחר ממה שירד לו מהכרטיס.
     */
    public static function perChargeAgorot(Forms\Get $get, ?Subscription $record): int
    {
        $base = filled($get('price_agorot_override'))
            ? (int) round(((float) $get('price_agorot_override')) * 100)
            : (int) (Plan::find($get('plan_id'))?->price_agorot ?? $record?->basePriceAgorot() ?? 0);

        $rate = self::vatRateFor($get, $record);

        return $base + (int) round($base * $rate);
    }

    /** שיעור המע״מ שיחול בפועל — 0 ללקוח פטור או למנוי שאינו נושא מע״מ. */
    public static function vatRateFor(Forms\Get $get, ?Subscription $record): float
    {
        $customer = Customer::find($get('customer_id')) ?? $record?->customer;

        if ($customer?->vat_exempt) {
            return 0.0;
        }

        $applies = Plan::find($get('plan_id'))?->vat_applies
            ?? (filled($get('vat_applies')) ? (bool) $get('vat_applies') : null)
            ?? $record?->vat_applies
            ?? true;

        return $applies ? (float) config('billing.vat_rate') : 0.0;
    }

    /** "14 × ₪500.00 = ₪7,000.00 · שולמו 3 מתוך 14 · נותרו ₪5,500.00" */
    public static function preview(Forms\Get $get, ?Subscription $record): string
    {
        $count = (int) $get('installments_total');

        if ($count < 1) {
            return '—';
        }

        $perCharge = self::perChargeAgorot($get, $record);

        if ($perCharge < 1) {
            return 'הזינו מחיר כדי לראות את סך הפריסה.';
        }

        $line = sprintf('%d × %s = %s', $count, Money::ils($perCharge), Money::ils($count * $perCharge));

        if ($record?->exists && $record->isInstallmentPlan()) {
            $line .= '  ·  '.$record->installmentSummary();
        }

        return $line;
    }
}
