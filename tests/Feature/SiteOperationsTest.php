<?php

namespace Tests\Feature;

use App\Enums\SiteChangeStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ViewSite;
use App\Jobs\RunSiteOperationJob;
use App\Models\Site;
use App\Models\SiteChange;
use App\Models\User;
use App\Services\Agent\McpClient;
use App\Services\Agent\SiteChangeJournal;
use App\Services\Agent\SiteOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * פעולות שמנהל מריץ על אתר מנוהל מהפאנל.
 *
 * להבדיל ממה שהסוכן מציע: שם המכונה מחליטה מה לעשות ואדם מאשר, וכאן אדם מחליט
 * והמכונה רק מבצעת. הפעולה הראשונה היא החלפת מפתחות ההצפנה של וורדפרס — מה
 * שמנתק בבת אחת כל התחברות פעילה באתר.
 *
 * מה שנשמר כאן: המחיר נאמר לפני הלחיצה, הפעולה אינה רצה בזמן הבקשה, מתג הביטחון
 * מכובד גם כשאדם לחץ, והתוצאה — הצלחה או כישלון — תמיד מגיעה למישהו וגם ליומן
 * השינויים של האתר. פעולה שקטה אינה ניתנת להבחנה מפעולה שלא יצאה לדרך.
 */
class SiteOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.actions_enabled' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function connectedSite(array $attributes = []): Site
    {
        // $attributes first: with `+` the left-hand side wins, so an override
        // passed by a test must sit there or it is silently ignored.
        return Site::factory()->create($attributes + [
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.com/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
            'mcp_capabilities' => ['tools' => [['name' => 'wp_salts_rotate']]],
        ]);
    }

    /** Answer the MCP call with $text, or fail it. */
    private function fakeSite(string $text = '{"rotated":["AUTH_KEY"]}', bool $ok = true): void
    {
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => ! $ok],
        ])]);
    }

    /*
    | ----------------------------------------------------------------
    | מה שנאמר לפני הלחיצה
    | ----------------------------------------------------------------
    */

    /** לכל פעולה כתוב גם מה היא עושה וגם מה היא עולה. */
    public function test_every_operation_states_what_it_costs(): void
    {
        $this->assertNotEmpty(SiteOperations::all());

        foreach (SiteOperations::all() as $key => $operation) {
            // מחיר שלא נכתב הוא מחיר שמישהו ישלם בלי לדעת.
            $this->assertNotEmpty($operation['cost'], "לפעולה {$key} אין מחיר כתוב");
            $this->assertNotEmpty($operation['what'], "לפעולה {$key} אין תיאור");
            $this->assertNotEmpty($operation['tool'], "לפעולה {$key} אין כלי");
        }
    }

    /** והמחיר של החלפת המפתחות הוא הניתוק — והוא נאמר. */
    public function test_the_rotation_says_that_everybody_gets_logged_out(): void
    {
        $cost = SiteOperations::find(SiteOperations::ROTATE_SALTS)['cost'];

        $this->assertStringContainsString('יתנתקו', $cost);
        $this->assertStringContainsString('סיסמאות', $cost);
    }

    /*
    | ----------------------------------------------------------------
    | הכפתור
    | ----------------------------------------------------------------
    */

    /** לחיצה מזמינה את הפעולה לרקע ואינה פונה לאתר בזמן הבקשה. */
    public function test_pressing_the_button_queues_the_work_instead_of_doing_it_in_the_request(): void
    {
        $this->actingAs($this->admin());
        $site = $this->connectedSite();
        Queue::fake([RunSiteOperationJob::class]);
        Http::fake();

        Livewire::test(ViewSite::class, ['record' => $site->id])
            ->callAction('siteOperation_'.SiteOperations::ROTATE_SALTS);

        Queue::assertPushed(RunSiteOperationJob::class,
            fn (RunSiteOperationJob $job): bool => $job->siteId === $site->id
                && $job->operation === SiteOperations::ROTATE_SALTS);

        Http::assertNothingSent();
    }

    /**
     * אתר שאינו יכול להריץ את הפעולה — הכפתור מושבת עם הסיבה, ולא נעלם.
     *
     * כפתור שנעלם נקרא כיכולת שאינה קיימת בפאנל, ואז מחפשים אותה במקום לתקן את
     * מה שחוסם אותה.
     */
    public function test_a_site_that_cannot_run_it_says_why_instead_of_hiding_the_button(): void
    {
        $this->actingAs($this->admin());
        $site = Site::factory()->create(['mcp_enabled' => false]);

        Livewire::test(ViewSite::class, ['record' => $site->id])
            ->assertActionVisible('siteOperation_'.SiteOperations::ROTATE_SALTS)
            ->assertActionDisabled('siteOperation_'.SiteOperations::ROTATE_SALTS);
    }

    /** ותוסף ישן מכדי להכיר את הכלי — נאמר ככזה. */
    public function test_an_old_plugin_is_named_as_the_reason(): void
    {
        $site = $this->connectedSite(['mcp_capabilities' => ['tools' => [['name' => 'wp_health']]]]);

        $this->assertFalse(SiteOperations::supportedOn($site, SiteOperations::ROTATE_SALTS));
    }

    /** אתר שמעולם לא נקראו ממנו היכולות אינו נחשב "בלי הכלי" — לא בדקנו אינו לא קיים. */
    public function test_a_site_never_handshaken_is_not_judged_as_missing_the_tool(): void
    {
        $site = $this->connectedSite(['mcp_capabilities' => null]);

        $this->assertTrue(SiteOperations::supportedOn($site, SiteOperations::ROTATE_SALTS));
    }

    /*
    | ----------------------------------------------------------------
    | הביצוע
    | ----------------------------------------------------------------
    */

    /** ביצוע מוצלח נרשם ביומן השינויים של האתר ומודיע למי שביקש. */
    public function test_a_completed_operation_is_journalled_and_reported(): void
    {
        $user = $this->admin();
        $site = $this->connectedSite();
        $this->fakeSite();

        (new RunSiteOperationJob($site->id, SiteOperations::ROTATE_SALTS, $user->id))
            ->handle(app(McpClient::class), app(SiteChangeJournal::class));

        $change = SiteChange::where('site_id', $site->id)->firstOrFail();

        $this->assertSame('wp_salts_rotate', $change->tool);
        $this->assertSame(SiteChangeStatus::Applied, $change->status);
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }

    /**
     * וכישלון נרשם גם הוא — ומדווח.
     *
     * פעולה שנכשלה בשקט נראית בדיוק כמו פעולה שהצליחה: שני המקרים הם מסך שלא
     * השתנה. ההבדל ביניהם הוא שבאחד מהם המשתמשים באתר עדיין מחוברים.
     */
    public function test_a_failed_operation_is_journalled_and_reported_too(): void
    {
        $user = $this->admin();
        $site = $this->connectedSite();
        $this->fakeSite('wp-config.php אינו ניתן לכתיבה', ok: false);

        (new RunSiteOperationJob($site->id, SiteOperations::ROTATE_SALTS, $user->id))
            ->handle(app(McpClient::class), app(SiteChangeJournal::class));

        $change = SiteChange::where('site_id', $site->id)->firstOrFail();

        $this->assertSame(SiteChangeStatus::Failed, $change->status);
        $this->assertStringContainsString('אינו ניתן לכתיבה', (string) $change->error);
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }

    /**
     * מתג הביטחון מכובד גם כשאדם לחץ על הכפתור.
     *
     * המתג אומר ששום דבר לא רץ על אתר של לקוח בזמן שהוא כבוי. "מנהל לחץ" אינו
     * חריג לכלל הזה — הוא בדיוק המקרה שבשבילו הוא קיים.
     */
    public function test_the_kill_switch_stops_it_even_when_a_manager_pressed_the_button(): void
    {
        config(['agent.actions_enabled' => false]);

        $user = $this->admin();
        $site = $this->connectedSite();
        Http::fake();

        (new RunSiteOperationJob($site->id, SiteOperations::ROTATE_SALTS, $user->id))
            ->handle(app(McpClient::class), app(SiteChangeJournal::class));

        Http::assertNothingSent();
        $this->assertSame(SiteChangeStatus::Failed, SiteChange::where('site_id', $site->id)->firstOrFail()->status);
    }

    /** פעולה שאינה ברשימה אינה רצה. */
    public function test_an_unknown_operation_does_nothing(): void
    {
        $site = $this->connectedSite();
        Http::fake();

        (new RunSiteOperationJob($site->id, 'drop_everything'))
            ->handle(app(McpClient::class), app(SiteChangeJournal::class));

        Http::assertNothingSent();
        $this->assertSame(0, SiteChange::count());
    }
}
