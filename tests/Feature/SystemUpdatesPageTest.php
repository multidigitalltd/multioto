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

    /** בדיקה טרייה שמצאה שהכול מעודכן — אישור חיובי, בלי אזהרה. */
    public function test_a_healthy_check_reports_being_up_to_date(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => true, 'behind' => 0, 'branch' => 'main']);

        Livewire::test(SystemUpdates::class)
            ->assertDontSee('בדיקת העדכונים אינה פועלת')
            ->assertSee('אתם מעודכנים');
    }

    /** יש עדכון ממתין — לא מוצג "אתם מעודכנים" לצדו. */
    public function test_a_pending_update_is_not_reported_as_up_to_date(): void
    {
        $this->writeCheck(['at' => now()->format('Y-m-d H:i'), 'ok' => true, 'behind' => 2, 'branch' => 'main']);
        file_put_contents($this->dir.'/available.json', json_encode(['behind' => 2, 'short' => 'abc1234', 'at' => now()->format('Y-m-d H:i')]));

        Livewire::test(SystemUpdates::class)
            ->assertSee('עדכון זמין')
            ->assertDontSee('אתם מעודכנים');
    }
}
