<?php

namespace App\Services\Calendar;

use Illuminate\Support\Carbon;

/**
 * Renders a civil date as its Hebrew-calendar equivalent — day, month and year
 * in Hebrew numerals (gematria) — for the built-in calendar, the way an Israeli
 * team reads a date ("כ״ג בתמוז תשפ״ו").
 *
 * Uses PHP's built-in Jewish calendar (the `calendar` extension). That calendar
 * numbers months the same way every year: 1=Tishrei … 5=Shevat, 6=Adar I,
 * 7=Adar (a common year) / Adar II (a leap year), 8=Nisan … 13=Elul — so the
 * month name for 6/7 is the only place the leap year matters (verified against
 * real Purim dates in both common and leap years).
 */
class HebrewDate
{
    /** Units place letters (0 is unused). */
    private const ONES = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];

    /** Tens place letters. */
    private const TENS = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];

    /** Hundreds place letters up to 400 (ת); 500+ stack ת's on top. */
    private const HUNDREDS = ['', 'ק', 'ר', 'ש', 'ת'];

    private const GERESH = "\u{05F3}";   // ׳ — after a single-letter numeral

    private const GERSHAYIM = "\u{05F4}"; // ״ — before the last letter of a multi-letter numeral

    /** The full Hebrew date, e.g. "כ״ג בתמוז תשפ״ו". */
    public static function format(?Carbon $date = null): string
    {
        [$month, $day, $year] = self::parts($date ?? Carbon::now());

        return self::numeral($day).' ב'.self::monthName($month, self::isLeapYear($year)).' '.self::numeral($year % 1000);
    }

    /** Just the day, in Hebrew numerals ("כ״ג", "ד׳") — for a calendar cell. */
    public static function day(Carbon $date): string
    {
        return self::numeral(self::parts($date)[1]);
    }

    /** Hebrew day-of-month as an integer (1–30) — for detecting Rosh Chodesh. */
    public static function dayOfMonth(Carbon $date): int
    {
        return self::parts($date)[1];
    }

    /** The Hebrew month + year ("תמוז תשפ״ו") for a date — header / Rosh Chodesh label. */
    public static function monthYear(Carbon $date): string
    {
        [$month, , $year] = self::parts($date);

        return self::monthName($month, self::isLeapYear($year)).' '.self::numeral($year % 1000);
    }

    /** Just the Hebrew month name ("אב") for a date — marks where a Hebrew month begins. */
    public static function month(Carbon $date): string
    {
        [$month, , $year] = self::parts($date);

        return self::monthName($month, self::isLeapYear($year));
    }

    /** A Jewish leap year (has Adar I) per the 19-year Metonic cycle. */
    public static function isLeapYear(int $jewishYear): bool
    {
        return (($jewishYear * 7 + 1) % 19) < 7;
    }

    /** @return array{0:int,1:int,2:int} [jewish month, jewish day, jewish year] for a civil date. */
    private static function parts(Carbon $date): array
    {
        return array_map(
            'intval',
            explode('/', jdtojewish(gregoriantojd($date->month, $date->day, $date->year)))
        );
    }

    private static function monthName(int $month, bool $leap): string
    {
        return match ($month) {
            1 => 'תשרי',
            2 => 'חשון',
            3 => 'כסלו',
            4 => 'טבת',
            5 => 'שבט',
            6 => 'אדר א׳',
            7 => $leap ? 'אדר ב׳' : 'אדר',
            8 => 'ניסן',
            9 => 'אייר',
            10 => 'סיון',
            11 => 'תמוז',
            12 => 'אב',
            13 => 'אלול',
            default => '',
        };
    }

    /** A number as a Hebrew numeral with geresh/gershayim (e.g. 15 → "ט״ו", 786 → "תשפ״ו"). */
    private static function numeral(int $n): string
    {
        $letters = self::letters($n);
        $chars = mb_str_split($letters);

        if (count($chars) <= 1) {
            return $letters.self::GERESH;
        }

        $last = array_pop($chars);

        return implode('', $chars).self::GERSHAYIM.$last;
    }

    /** The bare Hebrew letters for a number, with the טו/טז exceptions (avoiding the divine name). */
    private static function letters(int $n): string
    {
        $out = '';

        $hundreds = intdiv($n, 100);
        $n %= 100;

        // 500–900 are written as stacked ת (400) plus the remainder.
        while ($hundreds >= 4) {
            $out .= 'ת';
            $hundreds -= 4;
        }
        $out .= self::HUNDREDS[$hundreds];

        // 15 and 16 are written טו / טז, never יה / יו.
        if ($n === 15) {
            return $out.'טו';
        }
        if ($n === 16) {
            return $out.'טז';
        }

        $out .= self::TENS[intdiv($n, 10)];
        $out .= self::ONES[$n % 10];

        return $out;
    }
}
