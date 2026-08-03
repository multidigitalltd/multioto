<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\Trackers;

/**
 * האם בכלל אפשר לדעת מה קורה באתר הזה.
 *
 * אתר בלי מדידה הוא אתר שכל החלטה לגביו היא ניחוש: אי אפשר לדעת כמה אנשים
 * מגיעים, מאיפה, לאן הם נכנסים ואיפה הם עוזבים. זה גם הממצא היחיד בדוח שכולו
 * הזדמנות ולא תקלה — האתר עובד מצוין, פשוט אף אחד לא רואה מה קורה בו.
 *
 * הבדיקה שואלת אם יש מדידה כלשהי, לא איזו. לדרוש פיקסל של פייסבוק ממי שאינו
 * מפרסם בפייסבוק זו המלצה שנשמעת כמו מכירה.
 */
class Measurement implements Check, ReadsPage
{
    public function area(): string
    {
        return 'מדידה ומעקב';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        $found = Trackers::measuring($site->markup());

        if ($found !== []) {
            return [Finding::ok(
                $this->area(),
                'באתר מותקנת מערכת מדידה',
                'נמצאו: '.implode(', ', $found).'.',
            )];
        }

        return [Finding::warning(
            $this->area(),
            'אין באתר מערכת מדידה',
            'לא נמצאו Google Analytics, Tag Manager או כלי מדידה אחר. המשמעות היא שאי אפשר לדעת כמה אנשים מגיעים לאתר, מאיפה הם הגיעו, מה הם חיפשו ובאיזה שלב הם עזבו — וכל החלטה על האתר או על הפרסום נעשית בלי נתונים.',
            'להתקין Google Analytics 4 (חינם) — התקנה של פעם אחת, ומאותו רגע מצטברים נתונים.',
        )];
    }
}
