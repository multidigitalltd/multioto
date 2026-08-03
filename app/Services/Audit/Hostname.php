<?php

namespace App\Services\Audit;

/**
 * איזה חלק בכתובת הוא הדומיין עצמו, ואיזה הוא תת-דומיין.
 *
 * שאלה אחת שנשאלת בשני מקומות — בדיקת שתי צורות הכתובת (www) ובדיקת ה-SPF —
 * ולכן היא יושבת במקום אחד. שני עותקים של אותה ידיעה נפרדים זה מזה בשקט, ואז
 * אותו אתר מקבל תשובה אחת על www ותשובה סותרת על הדואר.
 *
 * זו הכרעה מעשית ולא רשימת ה-Public Suffix המלאה: היא מכסה את מה שנפוץ בישראל
 * (co.il, org.il, ac.il) ואת הסיומות הדו-מפלסיות המוכרות. במקרה גבול היא טועה
 * לכיוון של פחות ממצאים — התראת שווא במסמך שנשלח ללקוח יקרה יותר מממצא שהוחמץ.
 */
class Hostname
{
    /** תוויות שמאחוריהן מסתתר הדומיין עצמו — example.co.il הוא דומיין, לא תת-דומיין. */
    private const SECOND_LEVEL = [
        'co', 'com', 'net', 'org', 'ac', 'gov', 'muni', 'idf', 'k12', 'sch',
        'edu', 'ne', 'or', 'gr', 'biz', 'info', 'nic',
    ];

    /** הדומיין שנרשם אצל הרשם — shop.example.co.il ⇐ example.co.il. */
    public static function registrable(string $host): string
    {
        $labels = explode('.', trim(mb_strtolower(trim($host)), '.'));
        $count = count($labels);

        if ($count <= 2) {
            return implode('.', $labels);
        }

        $keep = in_array($labels[$count - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -min($keep, $count)));
    }

    /**
     * הצורה השנייה של הכתובת — כשבאמת יש כזו.
     *
     * www שייך לדומיין עצמו. shop.example.com הוא שם שמישהו בחר, ו-
     * www.shop.example.com הוא שם שהכלי היה ממציא.
     */
    public static function counterpart(string $host): ?string
    {
        $host = mb_strtolower(trim($host));

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return self::registrable($host) === $host ? 'www.'.$host : null;
    }
}
