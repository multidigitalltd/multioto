<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\WebhookSource;
use App\Jobs\IngestEmailMessageJob;
use App\Models\Customer;
use App\Models\SystemLog;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Support\AttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * הקובץ שהלקוח צירף במייל — ולמה הוא לא הגיע.
 *
 * הבדיקה הזו נולדה מתלונה: "לא מקבל את הקובץ המצורף הרבה פעמים, לפעמים כן".
 * ה"לפעמים כן" הוא מה שהסגיר את זה — צילומי מסך ו-PDF עברו, והשאר נעלם.
 *
 * הסיבה: סוג הקובץ נקבע מהרחת הבייטים, ו-libmagic מחזיר עבור קבצי Word ו-Excel
 * ישנים את `application/x-ole-storage`, ועבור כל קובץ docx/xlsx את
 * `application/zip`. אלה שמות של *מעטפת*, לא של פורמט — הם לא זיהו כלום. אבל
 * התשובה הזאת גברה על ה-Content-Type שהשולח הצהיר עליו, שאמר במדויק מה זה,
 * והקובץ נזרק. בשקט: שום לוג, שום סימן בכרטיס.
 *
 * הכלל שנכתב במקום: הרחה שזיהתה משהו היא הסמכות והיא פוסלת שקר — קובץ שהוכרז
 * PDF והבייטים שלו HTML נשאר בחוץ. הרחה שלא זיהתה דבר אינה עדות, ולכן אפשר
 * להתייעץ עם ההצהרה — שחייבת להיות בעצמה ברשימה המותרת, והסיומת תמיד מהמפה
 * שלנו.
 */
class InboundAttachmentTypesTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG, so finfo reports image/png. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Customer::factory()->create(['email' => 'lead@example.com']);
    }

    /** The OLE2 signature every legacy .doc/.xls/.ppt/.msg starts with. */
    private function ole2(): string
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 512);
    }

    /** A real zip, which is what every .docx/.xlsx/.odt actually is. */
    private function ooxml(string $inner): string
    {
        $path = tempnam(sys_get_temp_dir(), 'att').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString($inner, '<?xml version="1.0"?><x/>');
        $zip->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    /** Deliver one inbound email carrying one attachment, Postmark-shaped. */
    private function deliver(string $id, string $name, string $contents, ?string $declared): ?TicketMessage
    {
        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', $id, [
            'From' => 'Dana <lead@example.com>',
            'Subject' => "מצורף {$name}",
            'TextBody' => 'שלחתי את הקובץ',
            'MessageID' => $id,
            'Attachments' => [array_filter([
                'Name' => $name,
                'Content' => base64_encode($contents),
                'ContentType' => $declared,
                'ContentLength' => strlen($contents),
            ], fn ($v): bool => $v !== null)],
        ]);

        IngestEmailMessageJob::dispatchSync($event->id);

        return Ticket::query()->latest('id')->firstOrFail()
            ->messages()->where('direction', MessageDirection::Inbound)->first();
    }

    /**
     * The files that were being thrown away.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function refusedBefore(): array
    {
        return [
            'וורד ישן (.doc)' => ['invoice.doc', 'ole2', 'application/msword'],
            'אקסל ישן (.xls)' => ['sheet.xls', 'ole2', 'application/vnd.ms-excel'],
            'וורד חדש (.docx)' => ['invoice.docx', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'אקסל חדש (.xlsx)' => ['sheet.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'מייל שהועבר (.eml)' => ['forwarded.eml', 'eml', 'message/rfc822'],
            'RTF' => ['letter.rtf', 'rtf', 'application/rtf'],
        ];
    }

    #[DataProvider('refusedBefore')]
    public function test_a_file_the_sender_identified_correctly_is_kept(string $name, string $kind, string $declared): void
    {
        $contents = match ($kind) {
            'ole2' => $this->ole2(),
            'docx' => $this->ooxml('word/document.xml'),
            'xlsx' => $this->ooxml('xl/workbook.xml'),
            'eml' => "From: a@b.c\nSubject: x\n\nbody\n",
            'rtf' => '{\rtf1\ansi שלום}',
        };

        $message = $this->deliver('m-'.md5($name), $name, $contents, $declared);

        $this->assertNotNull($message);
        $this->assertCount(1, (array) $message->attachments, "{$name} was dropped.");

        $stored = $message->attachments[0];
        $this->assertArrayNotHasKey('rejected', $stored);
        // And under its own extension, not the container's: a .docx stored as
        // ".zip" is a file the team has to rename before anybody can open it.
        $this->assertStringEndsWith('.'.pathinfo($name, PATHINFO_EXTENSION), $stored['path']);
        Storage::disk('local')->assertExists($stored['path']);
    }

    /**
     * כל סוג קובץ מתקבל — כולל מה שעלול להכיל נוזקה.
     *
     * זו החלטה של הצוות, והיא הנכונה: תיבת תמיכה קיימת כדי לקבל את מה שלקוחות
     * באמת שולחים, ומי שמסתכל על הכרטיס שופט את הקובץ טוב יותר מרשימה שנכתבה
     * לפני חודשים. מה שמחליף את הסירוב הוא תיוג: הקובץ נשמר, ולידו כתוב מה הוא
     * ולמה להיזהר, לפני שלוחצים.
     */
    public function test_even_an_executable_is_kept_and_labelled_as_one(): void
    {
        $message = $this->deliver('m-exe', 'update.exe', "MZ\x90\x00".str_repeat("\x00", 64), 'application/octet-stream');

        $stored = $message->attachments[0];
        $this->assertArrayNotHasKey('rejected', $stored);
        $this->assertSame(AttachmentStore::RISK_EXECUTABLE, $stored['risk']);
        $this->assertStringContainsString('אל תפתחו אותו', (string) $stored['warning']);
        Storage::disk('local')->assertExists($stored['path']);
    }

    /**
     * והסיומת על הדיסק שלנו אינה הסיומת של השולח.
     *
     * זה לא על המחשב של הלקוח אלא על שלנו: ".php" על דיסק שהוא תקלת הגדרה אחת
     * מהשורש של האתר הוא shell, ו-".html" הוא XSS ביום שמישהו יגיש את התיקייה.
     * הקובץ נשמר — בשם ".bin" — ויורד בשמו האמיתי.
     */
    #[DataProvider('neverOnDisk')]
    public function test_a_file_our_own_server_could_run_is_stored_as_bin(string $name, string $bytes, ?string $declared): void
    {
        $message = $this->deliver('m-'.md5($name), $name, $bytes, $declared);

        $stored = $message->attachments[0];
        $this->assertStringEndsWith('.bin', $stored['path']);
        // The team still sees, and downloads, the name the customer used.
        $this->assertSame($name, $stored['name']);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    public static function neverOnDisk(): array
    {
        return [
            'PHP' => ['shell.php', "<?php echo 'x'; ?>", 'application/x-php'],
            'HTML' => ['page.html', '<!DOCTYPE html><html></html>', 'text/html'],
            'SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            'EXE' => ['setup.exe', "MZ\x90\x00", 'application/x-msdownload'],
            'shell script' => ['run.sh', "#!/bin/bash\necho hi\n", 'text/x-shellscript'],
        ];
    }

    /**
     * SVG ו-HTML לעולם אינם מוצגים בדפדפן — רק כהורדה.
     *
     * זה הפקק היחיד שהופך "לקבל הכל" לבטוח. SVG הוא תמונה בעיני אדם וסקריפט
     * בעיני דפדפן, והצגתו inline הייתה מריצה את הקוד של השולח על המקור של
     * הפאנל, עם ה-session של מי שפתח את הכרטיס.
     */
    public function test_active_content_is_served_as_an_opaque_download(): void
    {
        $this->actingAs(User::factory()->create());

        $message = $this->deliver('m-svg', 'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');

        $response = $this->get(route('support.attachment', ['message' => $message->id, 'index' => 0]))
            ->assertOk();

        // Never image/svg+xml, and never inline.
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** ותמונה רגילה כן מוצגת, אחרת הכרטיס מאבד את מה שהוא בעיקר מכיל. */
    public function test_an_ordinary_image_is_still_shown_inline(): void
    {
        $this->actingAs(User::factory()->create());

        $message = $this->deliver('m-png-inline', 'shot.png', base64_decode(self::PNG), 'image/png');

        $response = $this->get(route('support.attachment', ['message' => $message->id, 'index' => 0]))
            ->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * התיוג מתאר את הבייטים, לא את ההצהרה.
     *
     * תוכנה שהוכרזה "application/pdf" חייבת להיות מתויגת כתוכנה — אחרת ההצהרה
     * של השולח היא זו שקובעת אם מזהירים ממנו.
     */
    public function test_the_label_describes_the_bytes_not_the_claim(): void
    {
        $verdict = app(AttachmentStore::class)
            ->inspect('invoice.pdf', "MZ\x90\x00".str_repeat("\x00", 64), 'application/pdf');

        $this->assertTrue($verdict['ok']);
        $this->assertSame(AttachmentStore::RISK_EXECUTABLE, $verdict['risk']);
    }

    /** וסיומת מסוכנת מזהירה גם כשהבייטים אנונימיים. */
    public function test_a_dangerous_extension_warns_even_on_anonymous_bytes(): void
    {
        $verdict = app(AttachmentStore::class)
            ->inspect('invoice.exe', random_bytes(2048), 'application/octet-stream');

        $this->assertSame(AttachmentStore::RISK_EXECUTABLE, $verdict['risk']);
    }

    /** ארכיון וקובץ מאקרו מקבלים אזהרה רכה יותר, ולא נחסמים. */
    public function test_archives_and_macro_files_are_warned_about_softly(): void
    {
        $store = app(AttachmentStore::class);

        $zip = $store->inspect('logs.zip', $this->ooxml('logs/a.txt'), 'application/zip');
        $this->assertTrue($zip['ok']);
        $this->assertSame(AttachmentStore::RISK_ARCHIVE, $zip['risk']);

        $macro = $store->inspect('budget.xlsm', $this->ooxml('xl/workbook.xml'), 'application/vnd.ms-excel.sheet.macroEnabled.12');
        $this->assertSame(AttachmentStore::RISK_MACRO, $macro['risk']);
    }

    /** וקובץ רגיל אינו מקבל אזהרה בכלל — אחרת אף אזהרה לא נקראת. */
    public function test_an_ordinary_file_carries_no_warning(): void
    {
        $verdict = app(AttachmentStore::class)->inspect('invoice.pdf', $this->pdf(), 'application/pdf');

        $this->assertSame(AttachmentStore::RISK_SAFE, $verdict['risk']);
        $this->assertNull($verdict['warning']);
    }

    /**
     * מה שלא נשמר בכל זאת נרשם — לא נעלם.
     *
     * אחרי המעבר לקבלת כל הסוגים נשארו שתי סיבות לא לשמור: קובץ ריק וקובץ גדול
     * מהמותר. "הלקוח לא צירף כלום" ו"זרקנו את מה שצירף" נראו זהים לחלוטין
     * בכרטיס, וזו הסיבה שהבאג חי כל כך הרבה זמן: אין על מה להסתכל.
     */
    public function test_a_file_too_big_is_recorded_on_the_message_and_logged(): void
    {
        config(['billing.support.attachments.max_bytes' => 512]);

        $message = $this->deliver('m-big', 'huge.pdf', str_pad($this->pdf(), 4096, ' '), 'application/pdf');

        $this->assertNotNull($message);
        $attachments = (array) $message->attachments;
        $this->assertCount(1, $attachments);

        $this->assertArrayNotHasKey('path', $attachments[0]);
        $this->assertSame('huge.pdf', $attachments[0]['name']);
        $this->assertStringContainsString('גדול מהמותר', $attachments[0]['rejected']);

        $log = SystemLog::query()->where('source', 'support')->latest('id')->firstOrFail();
        $this->assertSame('warning', $log->level);
        $this->assertStringContainsString('huge.pdf', (string) $log->message);
        // The declared type is kept so a pattern in what gets refused can be read
        // off the log rather than guessed at.
        $this->assertSame('application/pdf', $log->context['declared_mime'] ?? null);
    }

    /** ושורה כזאת אינה מייצרת קישור שבור — אין קובץ, ואי אפשר להוריד אותו. */
    public function test_a_refused_row_is_not_downloadable(): void
    {
        config(['billing.support.attachments.max_bytes' => 512]);

        $message = $this->deliver('m-refused-2', 'huge.pdf', str_pad($this->pdf(), 4096, ' '), 'application/pdf');

        $this->actingAs(User::factory()->create());

        $this->get(route('support.attachment', ['message' => $message->id, 'index' => 0]))
            ->assertNotFound();
    }

    /** קובץ גדול מהמותר נדחה — ונאמר בכמה. */
    public function test_a_file_over_the_cap_is_refused_with_its_size(): void
    {
        config(['billing.support.attachments.max_bytes' => 1024]);

        $verdict = app(AttachmentStore::class)
            ->inspect('big.pdf', str_pad($this->pdf(), 4096, ' '), 'application/pdf');

        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('גדול מהמותר', (string) $verdict['reason']);
    }

    /** Content-Type עם charset עדיין נקרא נכון. */
    public function test_a_content_type_with_parameters_is_still_understood(): void
    {
        $verdict = app(AttachmentStore::class)
            ->inspect('invoice.doc', $this->ole2(), 'application/msword; charset=binary');

        $this->assertTrue($verdict['ok']);
        $this->assertSame('doc', $verdict['extension']);
    }

    /** ומה שעבד קודם ממשיך לעבוד. */
    public function test_a_screenshot_and_a_pdf_still_arrive(): void
    {
        $png = $this->deliver('m-png', 'screenshot.png', base64_decode(self::PNG), 'image/png');
        $this->assertSame('image/png', $png->attachments[0]['mime']);

        $pdf = $this->deliver('m-pdf', 'invoice.pdf', $this->pdf(), 'application/pdf');
        $this->assertSame('application/pdf', $pdf->attachments[0]['mime']);
    }
}
