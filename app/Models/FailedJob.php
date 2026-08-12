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
