<?php

namespace App\Services\Audit;

use App\Models\SiteAudit;

/**
 * מה השתנה באתר בין שתי בדיקות.
 *
 * זו התשובה לשאלה שנשאלת אחרי כל עבודה שנעשתה על אתר — "מה בעצם תוקן?" —
 * ובלעדיה כל בדיקה חוזרת היא עוד רשימה שצריך להשוות ביד מול הקודמת.
 *
 * ההשוואה נגזרת משני צילומי מצב שמורים ואינה בודקת דבר מחדש, ולכן היא אומרת
 * אותו דבר גם בעוד חצי שנה. הזיהוי של ממצא חוזר נעשה לפי התחום והכותרת, כשכל
 * רצף ספרות מנוטרל — כדי ש"האתר מחזיר שגיאה 500" ו"האתר מחזיר שגיאה 502" ייקראו
 * כאותה תקלה שנמשכת, ולא כאחת שתוקנה ואחת חדשה. המחיר ידוע: כותרת של בדיקה
 * שנוסחה מחדש בגרסה חדשה של המערכת תיראה כממצא שנפתר ואחד שנולד.
 */
class Comparison
{
    private function __construct(
        public readonly SiteAudit $current,
        public readonly ?SiteAudit $previous,
        public readonly ?string $unavailable,
    ) {}

    /** ההשוואה מול הבדיקה המשווה הקודמת של אותו אתר, אם יש כזו. */
    public static function for(SiteAudit $audit): self
    {
        if ($audit->status !== SiteAudit::STATUS_COMPLETED) {
            return new self($audit, null, 'ההשוואה זמינה רק לבדיקה שהושלמה.');
        }

        // בדיקה שנחסמה אינה בסיס להשוואה — רוב הבדיקות בה כלל לא רצו, וכל ממצא
        // שהיה בה ואינו בה עכשיו ייראה כאילו תוקן. דיווח שקרי על תיקון גרוע
        // בהרבה מהיעדר דיווח, במיוחד כשהוא מוצג ללקוח ששילם על העבודה.
        if ($audit->blocked()) {
            return new self($audit, null, 'הבדיקה הנוכחית נחסמה על ידי האתר, ולכן אין מה להשוות.');
        }

        $previous = $audit->previousComparable();

        if ($previous === null) {
            return new self($audit, null, 'זו הבדיקה המשווה הראשונה של האתר הזה. ההשוואה תופיע בבדיקה הבאה.');
        }

        return new self($audit, $previous, null);
    }

    public function available(): bool
    {
        return $this->previous !== null;
    }

    /** ממצאים שהיו בבדיקה הקודמת ואינם עוד. */
    public function fixed(): array
    {
        return $this->missingFrom($this->problems($this->previous), $this->problems($this->current));
    }

    /** ממצאים שלא היו קודם והופיעו עכשיו. */
    public function appeared(): array
    {
        return $this->missingFrom($this->problems($this->current), $this->problems($this->previous));
    }

    /** ממצאים שהיו קודם ועדיין כאן — בנוסח של הבדיקה הנוכחית. */
    public function remaining(): array
    {
        $before = array_flip(array_map(self::key(...), $this->problems($this->previous)));

        return array_values(array_filter(
            $this->problems($this->current),
            fn (array $finding): bool => isset($before[self::key($finding)]),
        ));
    }

    /** האם משהו בכלל זז בין שתי הבדיקות. */
    public function changed(): bool
    {
        return $this->fixed() !== [] || $this->appeared() !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $haystack
     * @param  list<array<string, mixed>>  $needles
     * @return list<array<string, mixed>>
     */
    private function missingFrom(array $needles, array $haystack): array
    {
        $known = array_flip(array_map(self::key(...), $haystack));

        return array_values(array_filter(
            $needles,
            fn (array $finding): bool => ! isset($known[self::key($finding)]),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function problems(?SiteAudit $audit): array
    {
        return $audit?->problems() ?? [];
    }

    /** @param array<string, mixed> $finding */
    private static function key(array $finding): string
    {
        $title = (string) ($finding['title'] ?? '');

        return ((string) ($finding['area'] ?? '')).'|'.preg_replace('/\d+/', '#', $title);
    }
}
