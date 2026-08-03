<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/**
 * How long the site takes to say its first word, and what it makes the browser
 * do before anything appears.
 *
 * Measured from one place at one moment, so the numbers are indicative and the
 * report says so. The structural findings — no compression, scripts that block
 * rendering — are facts about the page rather than about the network.
 */
class Speed implements Check, ReadsPage
{
    private const SLOW_MS = 1500;

    private const VERY_SLOW_MS = 3000;

    public function area(): string
    {
        return 'מהירות';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        return array_filter([
            $this->responseTime($site),
            $this->compression($site),
            $this->blockingScripts($site),
            $this->imageDimensions($site),
        ]);
    }

    private function responseTime(AuditContext $site): Finding
    {
        $ms = $site->home->ms;

        if ($ms >= self::VERY_SLOW_MS) {
            return Finding::critical(
                $this->area(),
                'הדף הראשי נטען לאט מאוד',
                'זמן טעינה כזה גורם לחלק ניכר מהמבקרים לעזוב לפני שהאתר בכלל מוצג, ופוגע בדירוג בגוגל.',
                'לבדוק אחסון, מטמון (cache) ותוספים כבדים. לרוב מדובר בשילוב של השלושה.',
                "{$ms} מילישניות",
            );
        }

        if ($ms >= self::SLOW_MS) {
            return Finding::warning(
                $this->area(),
                'הדף הראשי איטי',
                'זמן הטעינה גבוה ממה שמבקר מצפה לו, במיוחד בסלולר.',
                'להפעיל מטמון ולבדוק אילו תוספים מאטים את הטעינה.',
                "{$ms} מילישניות",
            );
        }

        return Finding::ok($this->area(), 'זמן התגובה תקין', "{$ms} מילישניות למדידה מנקודה אחת.");
    }

    private function compression(AuditContext $site): ?Finding
    {
        $encoding = mb_strtolower((string) $site->home->header('content-encoding'));

        if ($encoding !== '' && $encoding !== 'identity') {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'הדף נשלח ללא דחיסה',
            'הדפים נשלחים בגודל מלא במקום דחוסים. זו הגדרה אחת בשרת שמקצרת את זמן הטעינה מיידית.',
            'להפעיל דחיסת gzip או brotli בשרת.',
        );
    }

    private function blockingScripts(AuditContext $site): ?Finding
    {
        $head = $site->match('#<head\b[^>]*>(.*?)</head>#is') ?? '';
        $blocking = preg_match_all('#<script(?![^>]*\b(?:async|defer|type=["\']module["\']))[^>]*\bsrc=#i', $head);

        if ($blocking < 4) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'סקריפטים עוצרים את הצגת הדף',
            "בראש הדף יש {$blocking} סקריפטים שהדפדפן חייב להוריד לפני שהוא מצייר משהו. עד שהם מסתיימים המסך ריק.",
            'להוסיף defer או async לסקריפטים שאינם נחוצים לציור הראשוני.',
        );
    }

    /**
     * Images with no size declared make the page jump while it loads.
     */
    private function imageDimensions(AuditContext $site): ?Finding
    {
        $images = $site->occurrences('#<img\b#i');

        if ($images === 0) {
            return null;
        }

        $sized = $site->occurrences('#<img\b[^>]*\bwidth=[^>]*\bheight=#i')
            + $site->occurrences('#<img\b[^>]*\bheight=[^>]*\bwidth=#i');

        if ($sized >= $images * 0.6) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'לתמונות רבות אין מידות מוגדרות',
            'הדף "קופץ" בזמן הטעינה כשהתמונות נכנסות למקומן — חוויה מעצבנת, וגוגל מודד אותה.',
            'להוסיף width ו-height לתגיות התמונה.',
            "מתוך {$images} תמונות, ל-".max(0, $images - $sized).' אין מידות',
        );
    }
}
