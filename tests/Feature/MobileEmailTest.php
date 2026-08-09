<?php

namespace Tests\Feature;

use App\Mail\BroadcastMail;
use App\Mail\CustomerCardMail;
use App\Mail\DunningNotificationMail;
use App\Mail\MonitoringReportMail;
use App\Mail\NotificationMail;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * כל מייל שיוצא מהמערכת נקרא בטלפון.
 *
 * העיצוב שהמיילים יורשים בנוי סביב עמודה ברוחב 570 פיקסלים. במסך של 360 היא
 * אינה מתכווצת מעצמה — זו טבלה עם רוחב קבוע — ולכן הלקוח או מקטין את כל
 * ההודעה לגודל שאי אפשר לקרוא, או מאפשר גלילה לצדדים. שני הפתרונות של הדפדפן
 * גרועים, ושניהם נראים בדיוק כמו מייל שבור.
 *
 * הבדיקות כאן עוברות על **כל** ה-Mailables בפועל, ולא על תבנית אחת: מייל חדש
 * שייכתב מחר בלי הפריסה המשותפת ייפול כאן, ולא אצל הלקוח.
 */
class MobileEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * מופע אמיתי של כל סוג מייל שהמערכת שולחת.
     *
     * @return array<string, array{0: string}>
     */
    public static function mailables(): array
    {
        return [
            'הודעה כללית' => ['NotificationMail'],
            'תשובה לפנייה' => ['TicketReplyMail'],
            'תזכורת חוב' => ['DunningNotificationMail'],
            'דיוור' => ['BroadcastMail'],
            'דוח ניטור' => ['MonitoringReportMail'],
            'כרטיס לקוח' => ['CustomerCardMail'],
        ];
    }

    private function render(string $which): string
    {
        return match ($which) {
            'NotificationMail' => (new NotificationMail('נושא', "שורה\nשורה"))->render(),
            'TicketReplyMail' => (new TicketReplyMail('נושא', 'תשובה'))->render(),
            'DunningNotificationMail' => (new DunningNotificationMail('נושא', 'תזכורת תשלום'))->render(),
            'BroadcastMail' => (new BroadcastMail('נושא', 'תוכן', [
                'is_marketing' => true, 'business' => 'מולטי דיגיטל',
                'support' => null, 'note' => 'הערה', 'unsubscribe_url' => 'https://example.test/u',
            ]))->render(),
            'MonitoringReportMail' => (new MonitoringReportMail(
                Customer::factory()->create(['name' => 'לקוח']),
                ['window_days' => 30, 'sites' => [[
                    'domain' => 'example.co.il', 'uptime' => 99.98, 'avg_ms' => 320,
                    'incidents' => 0, 'down_minutes' => 0, 'ssl_days_left' => 60,
                    'domain_expiry_at' => '2027-01-01',
                ]]],
            ))->render(),
            'CustomerCardMail' => (new CustomerCardMail('לקוח', base64_encode('%PDF-1.4')))->render(),
        };
    }

    /** לכל מייל יש viewport — בלעדיו הטלפון מרנדר ברוחב שולחני ומקטין הכל. */
    #[DataProvider('mailables')]
    public function test_every_email_declares_a_mobile_viewport(string $which): void
    {
        $this->assertStringContainsString('name="viewport"', $this->render($which));
        $this->assertStringContainsString('width=device-width', $this->render($which));
    }

    /**
     * ולכל מייל יש כללי המסך הצר.
     *
     * הם חייבים לשבת ב-<style> ולא בתוך התכונות: מנוע ההטמעה שמכניס את העיצוב
     * לכל אלמנט אינו יודע להטמיע media query, כך שסגנון שנכתב רק בקובץ הערכה
     * מתאר תמיד את המסך הרחב בלבד.
     */
    #[DataProvider('mailables')]
    public function test_every_email_carries_the_narrow_screen_rules(string $which): void
    {
        $html = $this->render($which);

        $this->assertStringContainsString('max-width: 600px', $html);
        // העמודה הקבועה נפתחת לרוחב המסך…
        $this->assertMatchesRegularExpression('/\.inner-body[^}]*width: 100% !important/s', $html);
        // …והריפוד מצטמצם, אחרת נשארים פחות מ-300 פיקסלים לטקסט.
        $this->assertMatchesRegularExpression('/\.content-cell\s*\{\s*padding: 20px 16px !important/s', $html);
    }

    /** כפתור במסך צר הוא יעד מגע: רוחב מלא, ובלי שהמסגרות יוסיפו לו רוחב. */
    #[DataProvider('mailables')]
    public function test_buttons_fill_the_width_on_a_phone(string $which): void
    {
        $html = $this->render($which);

        $this->assertStringContainsString('max-width: 500px', $html);
        $this->assertMatchesRegularExpression('/\.button[^}]*box-sizing: border-box !important/s', $html);
    }

    /**
     * שום דבר אינו רחב מהמסך.
     *
     * טבלה או תמונה רחבה גוררת איתה הצדה כל שורת טקסט בהודעה, ולא רק את עצמה.
     */
    #[DataProvider('mailables')]
    public function test_nothing_may_be_wider_than_the_screen(string $which): void
    {
        $html = $this->render($which);

        $this->assertMatchesRegularExpression('/table, img\s*\{\s*max-width: 100% !important/s', $html);
        $this->assertStringContainsString('overflow-wrap: break-word !important', $html);
    }

    /**
     * הרשימה למעלה מכסה את כל סוגי המייל שקיימים באמת.
     *
     * בלי הבדיקה הזו, מייל חדש שייכתב מחר פשוט לא ייבדק — והכיסוי היה נשאר
     * מלא למראית עין בזמן שההודעה החדשה יוצאת ללקוחות בלי שאיש בדק אותה
     * בטלפון. כאן מוסיפים אותו לרשימה, או שהבדיקה נופלת.
     */
    public function test_the_list_above_covers_every_mailable_in_the_app(): void
    {
        $onDisk = collect(glob(app_path('Mail/*.php')))
            ->map(fn (string $path): string => basename($path, '.php'))
            ->sort()
            ->values();

        $covered = collect(self::mailables())
            ->map(fn (array $row): string => $row[0])
            ->sort()
            ->values();

        $this->assertSame(
            $onDisk->all(),
            $covered->all(),
            'יש Mailable שאינו נבדק כאן — הוסיפו אותו ל-mailables() ול-render().',
        );
    }

    /**
     * טבלת מפתח/ערך נערמת במסך צר.
     *
     * דוח הניטור מציג "זמינות" מול "99.98%" בשתי עמודות. בתוך 328 פיקסלים
     * העמודה השנייה מצטמצמת לשני תווים ברוחב, והמספר נשבר לאורך.
     */
    public function test_the_monitoring_report_table_stacks_on_a_phone(): void
    {
        $html = $this->render('MonitoringReportMail');

        $this->assertStringContainsString('class="mo-kv"', $html);
        $this->assertMatchesRegularExpression('/\.mo-kv td\s*\{\s*display: block !important/s', $html);
    }
}
