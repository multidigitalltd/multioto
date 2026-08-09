<?php

namespace App\Support;

/**
 * חלוקת סכום חוב למספר תשלומים — באגורות שלמות, בלי לשקר לגבי התוצאה.
 *
 * החלוקה עצמה טריוויאלית; מה שאינו טריוויאלי הוא מה שקורה כשהיא לא מתחלקת.
 * 7,000 ₪ ל-12 תשלומים אינם 583.33 (זה 6,999.96) ואינם 583.34 (זה 7,000.08).
 * המנוי גובה את אותו סכום בכל חודש, ולכן אחד משני אלה יקרה בפועל — והמספר
 * שמוצג למי שמזין חייב להיות זה, ולא הסכום שביקש. הפער מוחזר בנפרד כדי
 * שאפשר יהיה לומר אותו במפורש במקום להסתיר אותו מאחורי עיגול.
 *
 * מע״מ מחושב כאן באותה נוסחה בדיוק שבה הוא מחושב בזמן החיוב
 * (Subscription::vatAgorot). חישוב שונה במסך ובגבייה הוא הבטחה שאינה מקוימת.
 */
final class InstallmentSplit
{
    /**
     * @param  int  $totalAgorot  הסכום שסוכם עם הלקוח
     * @param  int  $count  מספר התשלומים
     * @param  float  $vatRate  שיעור המע״מ (0 כשאינו חל)
     * @param  bool  $totalIncludesVat  האם הסכום שהוזן כבר כולל מע״מ
     * @return array{
     *     base_agorot: int,
     *     vat_agorot: int,
     *     per_charge_agorot: int,
     *     collected_agorot: int,
     *     difference_agorot: int,
     * }
     */
    public static function compute(int $totalAgorot, int $count, float $vatRate, bool $totalIncludesVat): array
    {
        if ($count < 1 || $totalAgorot < 1) {
            return [
                'base_agorot' => 0, 'vat_agorot' => 0, 'per_charge_agorot' => 0,
                'collected_agorot' => 0, 'difference_agorot' => 0,
            ];
        }

        // הסכום שהוזן הוא מה שהלקוח משלם. המחיר שנשמר על המנוי הוא לפני מע״מ,
        // ולכן כשהוזן סכום שכולל מע״מ מחלצים ממנו את הבסיס.
        $baseTotal = ($totalIncludesVat && $vatRate > 0)
            ? (int) round($totalAgorot / (1 + $vatRate))
            : $totalAgorot;

        $base = (int) round($baseTotal / $count);
        $vat = (int) round($base * $vatRate);
        $perCharge = $base + $vat;
        $collected = $perCharge * $count;

        return [
            'base_agorot' => $base,
            'vat_agorot' => $vat,
            'per_charge_agorot' => $perCharge,
            'collected_agorot' => $collected,
            // חיובי = ייגבה יותר ממה שסוכם; שלילי = פחות. אפס הוא המקרה הנקי.
            'difference_agorot' => $collected - ($totalIncludesVat || $vatRate <= 0
                ? $totalAgorot
                : $totalAgorot + (int) round($totalAgorot * $vatRate)),
        ];
    }

    /**
     * משפט אחד שמתאר את התוצאה, כולל הפער אם יש.
     *
     * @param  array{per_charge_agorot: int, collected_agorot: int, difference_agorot: int}  $split
     */
    public static function describe(array $split, int $count): string
    {
        if ($count < 1 || $split['per_charge_agorot'] < 1) {
            return '—';
        }

        $line = sprintf(
            '%d × %s = %s',
            $count,
            Money::ils($split['per_charge_agorot']),
            Money::ils($split['collected_agorot']),
        );

        if ($split['difference_agorot'] !== 0) {
            $line .= sprintf(
                ' (%s %s מהסכום שהוזן — הסכום אינו מתחלק בדיוק)',
                $split['difference_agorot'] > 0 ? 'יותר ב-' : 'פחות ב-',
                Money::ils(abs($split['difference_agorot'])),
            );
        }

        return $line;
    }
}
