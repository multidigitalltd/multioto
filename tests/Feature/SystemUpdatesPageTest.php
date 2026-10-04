<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemUpdates;
use App\Models\User;
use App\Services\System\DeployManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * מסך "מערכת ועדכונים" — ובעיקר, מה הוא אומר כשהבדיקה עצמה לא עובדת.
 *
 * מסך ריק פירושו שני דברים שונים לגמרי: "אתם מעודכנים" ו"אף אחד לא בדק
 * שבועיים". רק אחד מהם בטוח לפעול לפיו.
 */
class SystemUpdatesPageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/multioto-ops-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app->bind(DeployManager::class, fn (): DeployManager => new DeployManager($this->dir));
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeCheck(array $check): void
    {
        file_put_contents($this->dir.'/update-check.json', json_encode($check));
    }

    /** סוכן שמעולם לא רץ — המסך אומר את זה, ומראה איך להתקין אותו. */
    public function test_it_says_the_agent_never_ran(): void
    {
        Livewire::test(SystemUpdates::class)
            ->assertOk()
            ->assertSee('בדיקת העדכונים אינה פועלת')
            ->assertSee('install-deploy-watcher.sh');
    }

    /** בדיקה שנכשלה — הסיבה מוצגת, כי בלעדיה אין מה לתקן. */
    public function test_it_shows_why_the_check_failed(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => false, 'error' => 'Permission denied (publickey)']);

        Livewire::test(SystemUpdates::class)
            ->assertSee('בדיקת העדכונים אינה פועלת')
            ->assertSee('Permission denied (publickey)');
    }

    /** בדיקה שהפסיקה לרוץ מזמן — שקט אינו ראיה לכך שאתם מעודכנים. */
    public function test_it_flags_a_check_that_stopped_running(): void
    {
        $this->writeCheck(['at' => now()->subHours(8)->format('Y-m-d H:i'), 'ok' => true, 'behind' => 0]);

        Livewire::test(SystemUpdates::class)
            ->assertSee('בדיקת העדכונים אינה פועלת')
            ->assertSee('crontab');
    }

    /**
     * בדיקה טרייה שמצאה שהכול מעודכן — אישור חיובי, בלי אזהרה.
     *
     * הסימון כולל את התו המפריד, כי העמוד מרנדר גם את יומן הגרסאות: ניסוח של
     * גרסה כלשהי שמצטט את המילים האלה היה מפיל בדיקה שאין לו קשר אליה.
     */
    public function test_a_healthy_check_reports_being_up_to_date(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => true, 'behind' => 0, 'branch' => 'main']);

        Livewire::test(SystemUpdates::class)
            ->assertDontSee('בדיקת העדכונים אינה פועלת')
            ->assertSee('· אתם מעודכנים');
    }

    /** יש עדכון ממתין — לא מוצג "אתם מעודכנים" לצדו. */
    public function test_a_pending_update_is_not_reported_as_up_to_date(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => true, 'behind' => 2, 'branch' => 'main']);
        file_put_contents($this->dir.'/available.json', json_encode(['behind' => 2, 'short' => 'abc1234', 'at' => now()->format('Y-m-d H:i')]));

        Livewire::test(SystemUpdates::class)
            ->assertSee('עדכון זמין')
            ->assertDontSee('· אתם מעודכנים');
    }

    /**
     * כפתור הרענון עונה, גם כשאין מה לקרוא.
     *
     * הכפתור קורא מחדש את מה שהסוכן בשרת כתב; הוא אינו יכול ללכת לבדוק בעצמו
     * (תהליך הווב לעולם אינו מריץ פקודת מעטפת). כלומר כשהסוכן מעולם לא רץ, כל
     * הקבצים שהוא קורא חסרים, שום דבר במסך לא משתנה, והכפתור נראה שבור — וכך
     * בדיוק זה דווח. תשובה, אפילו "לא היה מה לקרוא וזו הסיבה", היא ההבדל בין
     * כפתור מת לאבחנה.
     */
    public function test_the_refresh_button_says_the_agent_never_ran(): void
    {

        Livewire::test(SystemUpdates::class)
            ->callAction('checkAgain')
            ->assertNotified('סוכן העדכון בשרת מעולם לא רץ');
    }

    /** וכשהכל תקין הוא אומר גם את זה, עם מתי נבדק. */
    public function test_the_refresh_button_confirms_being_up_to_date(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => true, 'behind' => 0]);

        Livewire::test(SystemUpdates::class)
            ->callAction('checkAgain')
            ->assertNotified('אתם מעודכנים');
    }

    /** ובדיקה שנכשלה אינה נראית כמו "הכל בסדר". */
    public function test_the_refresh_button_does_not_dress_a_failure_as_fine(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => false, 'error' => 'permission denied']);

        Livewire::test(SystemUpdates::class)
            ->callAction('checkAgain')
            ->assertNotified('בדיקת העדכונים נכשלת');
    }

    /** יש עדכון, והכפתור שמבצע אותו אכן שם — ההנחיה היא ללחוץ עליו. */
    public function test_the_refresh_button_points_at_an_update_button_that_exists(): void
    {
        $this->freshCheckWithUpdate();

        Livewire::test(SystemUpdates::class)->callAction('checkAgain');

        $this->assertStringContainsString('עדכן עכשיו', $this->lastNotificationBody());
    }

    /**
     * עדכון שכבר התבקש — "לחצו עדכן עכשיו" הוא מבוי סתום: הכפתור מושבת.
     *
     * זו אותה תקלה שהעמוד הזה נולד לתקן, רק בכיוון ההפוך — להנחות אדם ללחוץ
     * על משהו שאינו ניתן ללחיצה.
     */
    public function test_it_does_not_tell_you_to_press_a_disabled_button(): void
    {
        $this->freshCheckWithUpdate();
        file_put_contents($this->dir.'/deploy.request', '{}');

        Livewire::test(SystemUpdates::class)->callAction('checkAgain');

        $body = $this->lastNotificationBody();
        $this->assertStringContainsString('אין צורך ללחוץ שוב', $body);
        $this->assertStringNotContainsString('אפשר ללחוץ', $body);
    }

    /** וכשהסוכן אינו מוגדר הכפתור מוסתר לגמרי — ההנחיה היא למשוך ידנית. */
    public function test_it_does_not_tell_you_to_press_a_hidden_button(): void
    {
        $this->freshCheckWithUpdate();

        // isConfigured() נשען על הרשאות כתיבה בספרייה, וכ-root הן תמיד קיימות
        // — ולכן הספרייה אינה הדרך לכבות אותה. מה שנבדק כאן הוא ההסתעפות
        // בעמוד, לא הבדיקה עצמה.
        $deploy = new class($this->dir) extends DeployManager
        {
            public function isConfigured(): bool
            {
                return false;
            }
        };
        $this->app->bind(DeployManager::class, fn (): DeployManager => $deploy);

        Livewire::test(SystemUpdates::class)->callAction('checkAgain');

        $body = $this->lastNotificationBody();
        $this->assertStringContainsString('אינו מוגדר בשרת', $body);
        $this->assertStringNotContainsString('אפשר ללחוץ', $body);
    }

    /** בדיקה טרייה שמצאה עדכון ממתין. */
    private function freshCheckWithUpdate(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => true, 'behind' => 3, 'branch' => 'main']);
        file_put_contents($this->dir.'/available.json', json_encode(['behind' => 3, 'short' => 'abc1234', 'at' => now()->format('Y-m-d H:i')]));
    }

    /**
     * גוף ההודעה האחרונה שנשלחה.
     *
     * שלוש ההסתעפויות חולקות את אותה כותרת, ולכן assertNotified (שמשווה כותרת
     * בלבד) אינו מבדיל ביניהן — ההבדל כולו בגוף ההודעה.
     */
    private function lastNotificationBody(): string
    {
        $sent = session()->get('filament.notifications', []);
        $this->assertNotEmpty($sent, 'לא נשלחה שום הודעה.');

        return (string) (end($sent)['body'] ?? '');
    }
}
