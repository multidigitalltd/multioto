<?php

namespace App\Filament\Concerns;

use App\Jobs\SendPaymentLinkJob;
use App\Models\Customer;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * דרישת תשלום (חשבונית עסקה) — הטופס והשליחה, במקום אחד.
 *
 * דרישה נפתחת משני מסכים — "דרישות תשלום" ומסך הלקוח — ומה שהיא עושה חייב להיות
 * זהה בשניהם: חשבונית עסקה, העברה בנקאית ראשונה וקישור כרטיס אחריה, תאריך יעד,
 * ונדנוד עד לתשלום. שני עותקים של אותו טופס נפרדים זה מזה בשקט, ואז ללקוח אחד
 * יוצא מסמך אחד ולשני מסמך אחר.
 *
 * לעולם לא נגבה כאן כרטיס שמור אוטומטית: דרישה היא בקשה לשלם, לא חיוב.
 */
trait OpensPaymentDemand
{
    /**
     * מה נדרש, כמה, עד מתי ולאן לשלוח — בלי השאלה למי.
     *
     * @return array<int, Forms\Components\Component>
     */
    protected function demandFields(string $channelDefault = 'email'): array
    {
        return [
            Forms\Components\TextInput::make('description')
                ->label('עבור (יופיע ללקוח ובחשבונית)')->default('תשלום')->maxLength(120)->required(),
            Forms\Components\Repeater::make('items')
                ->label('פירוט פריטים (אופציונלי)')
                ->helperText('הוסיפו פריטים כדי שהלקוח יראה פירוט לפי מוצר. המחיר מוזן לפני מע״מ, ולכל פריט בוחרים אם להוסיף מע״מ. אם ריק — תישלח שורת הסכום שלמטה.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('פריט')->maxLength(120)->required()->columnSpan(2),
                    Forms\Components\TextInput::make('qty')->label('כמות')->numeric()->default(1)->minValue(1)->required(),
                    Forms\Components\TextInput::make('unit_price')->label('מחיר ליח׳ (₪, לפני מע״מ)')
                        ->numeric()->prefix('₪')->step('0.01')->minValue(0)->inputMode('decimal')->required(),
                    Forms\Components\Toggle::make('add_vat')->label('להוסיף מע״מ')
                        ->default(true)->inline(false),
                ])
                ->columns(5)->addActionLabel('הוסף פריט')->default([]),
            Forms\Components\TextInput::make('amount')
                ->label('סכום לתשלום (₪, כולל מע״מ)')
                ->helperText('בשימוש רק כשאין פירוט פריטים.')
                ->numeric()->prefix('₪')->step('0.01')->minValue(0)->inputMode('decimal')
                ->requiredWithout('items'),
            Forms\Components\DatePicker::make('due_at')
                ->label('לתשלום עד')
                ->helperText('התאריך שעד אליו מצופה שהתשלום יתקבל — מוצג במעקב ומשמש את מסך תזרים וגבייה.')
                ->default(fn (): Carbon => now()->addDays((int) config('billing.demands.due_days', 14)))
                ->minDate(fn (): Carbon => now()->startOfDay())
                ->native(false)->firstDayOfWeek(7),
            Forms\Components\Radio::make('channel')
                ->label('לשלוח דרך')
                ->options(['email' => 'מייל', 'whatsapp' => 'וואטסאפ'])
                ->default($channelDefault)->required()->live()
                ->helperText('הדרישה מדגישה תשלום בהעברה בנקאית (הדרך המועדפת) ומאפשרת גם קישור לתשלום בכרטיס — לעולם לא מתבצע חיוב אוטומטי.'),
        ];
    }

    /**
     * הסכום שנדרש, באגורות — מהפירוט אם יש, ואחרת מהשדה.
     *
     * @param  array<string, mixed>  $data
     */
    protected function demandTotal(array $data): int
    {
        $lines = $this->demandLines($data['items'] ?? []);

        return $lines !== []
            ? array_sum(array_map(fn (array $line): int => $line['qty'] * $line['unit_price_agorot'], $lines))
            : (int) round(((float) ($data['amount'] ?? 0)) * 100);
    }

    /**
     * שליחת הדרישה ללקוח, אחרי שנבדק שיש מה לשלוח ולאן.
     *
     * @param  array<string, mixed>  $data
     * @return bool האם נשלחה
     */
    protected function sendDemand(Customer $customer, array $data): bool
    {
        $lines = $this->demandLines($data['items'] ?? []);
        $totalAgorot = $this->demandTotal($data);

        if ($totalAgorot <= 0) {
            Notification::make()->title('סכום לא תקין')->danger()->send();

            return false;
        }

        $channel = $data['channel'] ?? 'email';
        $missing = $channel === 'email' ? blank($customer->email) : (blank($customer->whatsapp_jid) && blank($customer->phone));

        if ($missing) {
            Notification::make()->title('אין ללקוח פרטי '.($channel === 'email' ? 'מייל' : 'וואטסאפ'))->danger()->send();

            return false;
        }

        // A demand always offers BOTH options; bank transfer is listed first (our
        // preferred method) and the (non-auto-charging) card link second. The
        // proforma is issued by the job.
        $dueAt = filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at'])->toDateString() : null;

        SendPaymentLinkJob::dispatch(
            $customer->id,
            $totalAgorot,
            filled($data['description'] ?? null) ? (string) $data['description'] : 'תשלום',
            $channel,
            $lines,
            ['transfer', 'link'],
            $dueAt,
        );

        Notification::make()
            ->title('דרישת התשלום נשלחה')
            ->body('נוצרה חשבונית עסקה ונשלחה ל'.$customer->name.' ב'.($channel === 'email' ? 'מייל' : 'וואטסאפ').' עם קישור לתשלום ופרטי העברה. עם התשלום תיסגר החשבונית עסקה ותונפק חשבונית מס/קבלה.')
            ->success()->send();

        return true;
    }

    /**
     * Normalise the items repeater into charge line rows (agorot), dropping blank
     * rows. The unit price is entered NET (pre-VAT); when "add_vat" is on we gross
     * it up by the configured VAT rate, so the stored unit_price_agorot is always
     * the final billed price — keeping the charge/invoice pipeline unchanged.
     * Returns [] when nothing usable was entered.
     *
     * @param  array<int, array{name?: string, qty?: mixed, unit_price?: mixed, add_vat?: mixed}>  $items
     * @return array<int, array{name: string, qty: int, unit_price_agorot: int}>
     */
    protected function demandLines(array $items): array
    {
        $vatRate = (float) config('billing.vat_rate');

        return collect($items)
            ->map(function (array $item) use ($vatRate): array {
                $net = (float) ($item['unit_price'] ?? 0);
                $gross = ($item['add_vat'] ?? true) ? $net * (1 + $vatRate) : $net;

                return [
                    'name' => trim((string) ($item['name'] ?? '')),
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                    'unit_price_agorot' => (int) round($gross * 100),
                ];
            })
            ->filter(fn (array $line): bool => $line['name'] !== '' && $line['unit_price_agorot'] > 0)
            ->values()
            ->all();
    }
}
