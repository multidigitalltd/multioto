<?php

namespace Tests\Unit;

use App\Support\GraphApiVersion;
use PHPUnit\Framework\TestCase;

/**
 * הרצפה של גרסת Graph API.
 *
 * מטא מוציאה גרסה משימוש כשנתיים אחרי שיצאה, וגרסה שפגה אינה מתדרדרת — כל
 * קריאה אליה נכשלת, והבוט משתתק לגמרי בלי שגיאה בשום מסך.
 *
 * מה שהבדיקות כאן שומרות עליו הוא המקרה שאינו אינטואיטיבי: שינוי ברירת המחדל
 * שבקוד אינו נוגע בהתקנה קיימת, כי ב-`.env` שלה יושב הערך שהועתק מהדוגמה
 * כשהוקמה — והוא מנצח כל ברירת מחדל חדשה. בלי הרצפה, התיקון היה נראה כאילו
 * נעשה ולא היה משנה דבר באף התקנה שקיימת היום.
 */
class GraphApiVersionTest extends TestCase
{
    public function test_a_version_older_than_the_floor_is_raised_to_it(): void
    {
        // זה הערך שיושב היום ב-env של כל התקנה שהוקמה מהדוגמה הקודמת.
        $this->assertSame(GraphApiVersion::FLOOR, GraphApiVersion::resolve('v21.0'));
    }

    public function test_a_newer_version_is_honoured(): void
    {
        $this->assertSame('v99.0', GraphApiVersion::resolve('v99.0'));
    }

    public function test_the_floor_itself_passes_through(): void
    {
        $this->assertSame(GraphApiVersion::FLOOR, GraphApiVersion::resolve(GraphApiVersion::FLOOR));
    }

    /** ריק, חסר, או צורה שאינה גרסה — הרצפה, ובלי להפיל את טעינת ההגדרות. */
    public function test_anything_unusable_falls_back_to_the_floor(): void
    {
        foreach ([null, '', '   ', 'v21.0/', 'latest', '21.0', 'vX', '/'] as $input) {
            $this->assertSame(
                GraphApiVersion::FLOOR,
                GraphApiVersion::resolve($input),
                sprintf('input %s', var_export($input, true)),
            );
        }
    }

    /** והרצפה עצמה חייבת להיות גרסה תקינה, אחרת כל הקריאות שבורות. */
    public function test_the_floor_is_a_well_formed_version(): void
    {
        $this->assertMatchesRegularExpression('/^v\d+\.\d+$/', GraphApiVersion::FLOOR);
    }

    /**
     * והחיווט עצמו — זה מה שהיה הבאג, ולא הלוגיקה.
     *
     * הרצפה שאינה מחוברת ל-env היא תיקון שנראה כאילו נעשה ואינו משנה דבר באף
     * התקנה קיימת, כי שם יושב `v21.0` שהועתק מהדוגמה הקודמת. לכן הבדיקה טוענת
     * את קובץ ההגדרות עם הערך הזה ב-env ומוודאת מה יוצא ממנו בפועל.
     */
    public function test_the_config_sends_a_stale_env_value_through_the_floor(): void
    {
        $previous = getenv('SITE_AGENT_WA_API_VERSION');

        putenv('SITE_AGENT_WA_API_VERSION=v21.0');
        $_ENV['SITE_AGENT_WA_API_VERSION'] = 'v21.0';

        try {
            $config = require dirname(__DIR__, 2).'/config/siteagent.php';

            $this->assertSame(GraphApiVersion::FLOOR, $config['whatsapp']['api_version']);
        } finally {
            unset($_ENV['SITE_AGENT_WA_API_VERSION']);
            $previous === false
                ? putenv('SITE_AGENT_WA_API_VERSION')
                : putenv('SITE_AGENT_WA_API_VERSION='.$previous);
        }
    }
}
