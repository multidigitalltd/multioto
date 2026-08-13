<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * עבודה שנכשלה — בשפה של מי שצריך להחליט מה לעשות איתה.
 *
 * כל שורה כאן היא משהו שהמערכת יצאה לעשות ולא עשתה: תשובה שלא נשלחה, חשבונית
 * שלא הונפקה, אתר שלא נבדק. השורה עצמה שמורה בשפה של המתכנת — שם מחלקה,
 * payload מקודד ו-stack trace באנגלית — ולכן היא נקראה עד עכשיו רק במסך הטכני
 * של Horizon, ובפועל לא נקראה כלל.
 *
 * המודל הזה מתרגם: מה זו הייתה העבודה, על מי היא, למה היא נכשלה, ומה קורה אם
 * לא יעשו איתה כלום. בלי זה "19 עבודות שנכשלו" הוא מספר שאי אפשר לפעול לפיו,
 * וזה בדיוק מה שקרה כאן: הן ישבו חודש.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /**
     * מה זו הייתה העבודה, בעברית, ומה המשמעות של אי-ביצועה.
     *
     * הרשימה מכסה את מה שנכשל בפועל בעבודה יומיומית. עבודה שאינה ברשימה מוצגת
     * בשמה הטכני — עדיף שם באנגלית מאשר תיאור שהומצא, כי מי שקורא כאן מחליט
     * לפי מה שכתוב.
     */
    private const JOBS = [
        'ChargeSubscriptionJob' => ['חיוב מנוי', 'הלקוח לא חויב עבור התקופה. אם החיוב לא ירוץ — הכסף לא ייגבה והמנוי לא יתקדם.'],
        'IssueInvoiceJob' => ['הנפקת חשבונית', 'הכסף נגבה אבל החשבונית לא הונפקה בלינט. זו חשיפה מול רשויות המס — צריך להנפיק.'],
        'SendTicketReplyJob' => ['שליחת תשובה ללקוח', 'התשובה נכתבה ולא יצאה. הלקוח ממתין ולא יודע שענינו.'],
        'SendTicketNotificationJob' => ['הודעת מערכת ללקוח על פנייה', 'אישור קבלה או הודעת סגירה שלא הגיעו ללקוח.'],
        'SendDunningNotificationJob' => ['תזכורת חוב ללקוח', 'שלב בגבייה שלא נשלח — הלקוח לא יודע שהחיוב נכשל.'],
        'IngestWhatsappMessageJob' => ['קליטת הודעת וואטסאפ', 'הודעה שלקוח שלח ולא נפתחה ממנה פנייה. הוא ממתין לתשובה שאיש לא ראה שהוא מחכה לה.'],
        'IngestEmailMessageJob' => ['קליטת מייל נכנס', 'מייל שהגיע לכתובת התמיכה ולא הפך לפנייה.'],
        'SendBroadcastJob' => ['שליחת דיוור', 'הדיוור לא נשלח (או נשלח חלקית).'],
        'RunSiteAuditJob' => ['בדיקת אתר', 'הבדיקה לא הסתיימה — לא נוצר דוח.'],
        'MonitorSiteJob' => ['ניטור אתר', 'בדיקת זמינות שלא רצה. אתר שנפל באותו רגע לא היה מזוהה.'],
        'SendMonthlyMonitoringReportJob' => ['דוח ניטור חודשי', 'הדוח החודשי לא נשלח ללקוח.'],
        'SendCardCaptureLinkJob' => ['שליחת קישור להזנת כרטיס', 'הלקוח לא קיבל את הקישור, ולכן לא יזין כרטיס.'],
        'RequestMissingCardJob' => ['בקשת כרטיס מלקוח', 'הבקשה לא נשלחה.'],
        'CheckSiteDnsJob' => ['בדיקת DNS', 'שינוי ב-DNS לא היה מזוהה בהרצה הזו.'],
        'CheckDomainExpiryJob' => ['בדיקת תוקף דומיין', 'תוקף הדומיין לא עודכן בהרצה הזו.'],
        'CheckSitePluginChangesJob' => ['מעקב תוספים ומנהלים', 'שינוי באתר לא היה מזוהה בהרצה הזו.'],
        'RefreshCloudflareCountryRulesJob' => ['קריאת כללי המדינות מ-Cloudflare', 'מסך כללי המדינות מציג קריאה ישנה יותר. הכללים עצמם לא הושפעו.'],
    ];

    /** שם המחלקה הקצר של העבודה. */
    public function jobClass(): string
    {
        $payload = json_decode((string) $this->payload, true);

        $name = (string) ($payload['displayName'] ?? data_get($payload, 'data.commandName') ?? '');

        return $name === '' ? 'עבודה לא מזוהה' : class_basename($name);
    }

    /** מה זו הייתה העבודה, בעברית. */
    public function label(): string
    {
        return self::JOBS[$this->jobClass()][0] ?? $this->jobClass();
    }

    /** מה המשמעות של זה שהיא לא רצה — או null כשאיננו יודעים לומר. */
    public function meaning(): ?string
    {
        return self::JOBS[$this->jobClass()][1] ?? null;
    }

    /**
     * שורת השגיאה, בלי ה-stack trace.
     *
     * השורה הראשונה היא מה שקרה; כל השאר הוא איפה בקוד, ואינו עוזר להחליט אם
     * לנסות שוב. הנתיב המלא של הקובץ נחתך — הוא רועש ואינו מוסיף דבר כאן.
     */
    public function shortError(): string
    {
        $first = trim(Str::before((string) $this->exception, "\n"));
        $first = (string) preg_replace('#\s+in /[^\s]+:\d+$#', '', $first);

        return $first === '' ? 'שגיאה לא ידועה' : Str::limit($first, 300);
    }

    /**
     * עבודות שאסור להריץ מכאן שוב, וההסבר מה כן לעשות במקום.
     *
     * שתי סיבות שונות, ושתיהן חמורות:
     *
     *  · **הרצה חוזרת תכפיל פעולה שכבר קרתה.** הסוכן ירוץ פעם שנייה ויגיש את
     *    אותן הצעות שוב; דיוור ישלח שוב למי שכבר קיבל; שחזור או גיבוי ירוצו
     *    על מערכת חיה. אלה עבודות שהוגדרו במפורש כ"ניסיון אחד" בדיוק מהסיבה
     *    הזו, וכפתור "ניסיון חוזר להכול" היה עוקף את ההחלטה ההיא.
     *
     *  · **הרצה חוזרת לא תעשה כלום, ותדווח שהצליחה.** עבודה שמטפל הכישלון שלה
     *    שינה את המצב שהיא בודקת בכניסה תחזור מיד — והמסך היה מוחק את שורת
     *    הכישלון ומודיע שהיא הוחזרה לתור. זו בדיוק השתיקה שהמסך הזה נבנה כדי
     *    לסלק.
     */
    private const NOT_RETRYABLE = [
        'RunAgentInstructionJob' => 'הרצה חוזרת תפעיל את הסוכן פעם שנייה ותגיש את אותן הצעות שוב. אם הבקשה עדיין רלוונטית — כתבו אותה שוב בקבוצת הניהול.',
        'InvestigateSiteJob' => 'הרצה חוזרת תפעיל את הסוכן פעם שנייה על אותו אתר. להרצה מכוונת: עמוד האתר ← "אבחון AI".',
        'InvestigateTicketJob' => 'הרצה חוזרת תפעיל את הסוכן פעם שנייה על אותה פנייה. להרצה מכוונת: עמוד הפנייה.',
        'RunSiteAuditJob' => 'הבדיקה כבר סומנה ככושלת, והרצה חוזרת של אותה עבודה תסתיים מיד בלי לעשות דבר. להרצה אמיתית: כלים ← בדיקת אתר ← "בדיקה חוזרת".',
        'SendBroadcastJob' => 'הרצה חוזרת עלולה לשלוח את הדיוור שוב למי שכבר קיבל אותו. בדקו במסך הדיוורים מה נשלח בפועל.',
        'RunBackupJob' => 'גיבוי מריצים מהמסך שלו, אחרי שמוודאים שהתקלה שגרמה לכישלון טופלה: ניהול ← גיבויים.',
        'RestoreBackupJob' => 'שחזור לעולם אינו רץ מחדש מכאן — הוא מוחלף על מערכת חיה. ניהול ← גיבויים.',
        'DrillBackupJob' => 'בדיקת השחזור רצה מהמסך שלה: ניהול ← גיבויים.',
        'ImportBackupsJob' => 'ייבוא גיבויים מריצים מהמסך שלו: ניהול ← גיבויים.',
    ];

    /** האם מותר להריץ את העבודה הזו שוב מהמסך. */
    public function retryable(): bool
    {
        return ! array_key_exists($this->jobClass(), self::NOT_RETRYABLE);
    }

    /** למה לא, ומה לעשות במקום — או null כשמותר. */
    public function retryNote(): ?string
    {
        return self::NOT_RETRYABLE[$this->jobClass()] ?? null;
    }

    /**
     * האם השגיאה נראית זמנית — רשת, timeout, שירות שלא ענה.
     *
     * ניסיון חוזר על כשל כזה הוא בדרך כלל כל מה שצריך; על שגיאת קוד או נתונים
     * הוא רק ייכשל שוב, ולכן ההבחנה נאמרת במסך ולא נשארת לניחוש.
     */
    public function looksTransient(): bool
    {
        $error = mb_strtolower($this->shortError());

        foreach (['timeout', 'timed out', 'curl', 'connection', 'could not resolve',
            'network', 'temporarily', 'ssl', '502', '503', '504', 'too many requests', 'deadlock'] as $needle) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
