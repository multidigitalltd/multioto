<?php

namespace Tests\Feature;

use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Agent\ConsoleAgent;
use App\Services\Agent\McpClient;
use App\Services\Agent\RevertRecipe;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the people on a site, and the pictures on it.
 *
 * The line that matters here is the one the agent may never cross: it can add
 * an editor and approve a subscriber, and it has no vocabulary at all for
 * administrators. The console is reachable from a phone over a shared secret,
 * so "the agent can mint an admin" is not a feature with a risk attached — it
 * is the whole account, handed to whoever gets in.
 *
 * The second rule is smaller and just as deliberate: a picture goes up with a
 * description or it does not go up. An agent placing images is an agent nobody
 * proof-read, and "alt later" is how a site ends up with four hundred images
 * and no descriptions.
 */
class SiteUserAndMediaToolsTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN_DIR = __DIR__.'/../../wordpress-plugin/multioto-agent';

    private function site(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.co.il/wp-json/md-agent/v1/mcp',
        ]);
    }

    private function agent(): ConsoleAgent
    {
        return new ConsoleAgent(
            $this->createMock(ClaudeClient::class),
            app(ApprovalGate::class),
            $this->createMock(McpClient::class),
        );
    }

    /** @param  array<string, mixed>  $input */
    private function tool(string $tool, array $input): array
    {
        $agent = $this->agent();
        $method = new \ReflectionMethod($agent, 'handle');
        $method->setAccessible(true);

        return (array) $method->invoke($agent, $tool, $input);
    }

    // ---- the line the agent does not cross ---------------------------------

    /** תפקיד מנהל אתר אינו ניתן להקצאה — לא בהוספה ולא בשינוי. */
    public function test_the_agent_will_not_propose_an_administrator(): void
    {
        $site = $this->site();

        foreach ([
            ['propose_add_user', ['site_id' => $site->id, 'email' => 'a@example.co.il', 'role' => 'administrator']],
            ['propose_set_user_role', ['site_id' => $site->id, 'user_id' => 4, 'role' => 'administrator']],
        ] as [$tool, $input]) {
            $result = $this->tool($tool, $input);

            $this->assertTrue($result['is_error'], "{$tool} should refuse an administrator");
            $this->assertStringContainsString('מנהל אתר', $result['content']);
        }

        // Refused BEFORE proposing: a manager must never be shown an approval
        // for something that cannot and must not run.
        $this->assertSame(0, PendingAction::count());
    }

    /** וגם תפקיד שאינו ברשימה נדחה, ולא מוצע ונכשל אחר כך. */
    public function test_an_unknown_role_is_refused_before_it_is_proposed(): void
    {
        $result = $this->tool('propose_set_user_role', [
            'site_id' => $this->site()->id, 'user_id' => 4, 'role' => 'super_duper',
        ]);

        $this->assertTrue($result['is_error']);
        $this->assertSame(0, PendingAction::count());
    }

    /** התוסף עצמו אינו מכיר את התפקיד הזה — לא רק הפאנל. */
    public function test_the_plugin_itself_never_lists_administrator_as_assignable(): void
    {
        $source = file_get_contents(self::PLUGIN_DIR.'/includes/class-users.php');

        preg_match('/const ASSIGNABLE = \[(.*?)\];/s', $source, $match);

        $this->assertNotEmpty($match, 'The assignable-role list must stay findable.');
        $this->assertStringNotContainsString('administrator', $match[1],
            'administrator must never be assignable — the console is reachable from a phone.');
    }

    /** ו-SVG אינו סוג קובץ שמותר להעלות: וורדפרס מגיש אותו כפי שהוא, וזה סקריפט. */
    public function test_the_plugin_never_allows_an_svg_upload(): void
    {
        $source = file_get_contents(self::PLUGIN_DIR.'/includes/class-media.php');

        preg_match('/const ALLOWED = \[(.*?)\];/s', $source, $match);

        $this->assertNotEmpty($match);
        $this->assertStringNotContainsString('svg', strtolower($match[1]));
    }

    // ---- ordinary work, proposed properly ----------------------------------

    /** הוספת משתמש מוצעת עם התפקיד ובלי סיסמה. */
    public function test_adding_a_user_is_proposed_with_its_role(): void
    {
        $site = $this->site();

        $result = $this->tool('propose_add_user', [
            'site_id' => $site->id,
            'email' => 'dana@example.co.il',
            'role' => 'editor',
            'display_name' => 'דנה',
        ]);

        $this->assertArrayNotHasKey('is_error', $result);

        $action = PendingAction::sole();
        $this->assertSame('wp_user_create', $action->payload['tool']);
        $this->assertSame('editor', $action->payload['arguments']['role']);
        $this->assertSame('dana@example.co.il', $action->payload['arguments']['email']);

        // The approval says out loud that no password is set here.
        $this->assertStringContainsString('סיסמה אינה נקבעת', $action->summary);
        $this->assertArrayNotHasKey('user_pass', $action->payload['arguments']);
    }

    /** ושינוי תפקיד מצטט את התפקיד הנוכחי, כדי שיהיה מה לאשר. */
    public function test_a_role_change_quotes_the_role_it_is_changing_from(): void
    {
        $site = $this->site();

        $this->tool('propose_set_user_role', [
            'site_id' => $site->id, 'user_id' => 12, 'role' => 'author', 'current_role' => 'subscriber',
        ]);

        $action = PendingAction::sole();
        $this->assertStringContainsString('subscriber ← author', $action->summary);
        $this->assertSame(['user_id' => 12, 'role' => 'author'], $action->payload['arguments']);
    }

    // ---- pictures ----------------------------------------------------------

    /** תמונה בלי טקסט חלופי אינה מוצעת בכלל. */
    public function test_an_image_without_alt_text_is_refused(): void
    {
        $result = $this->tool('propose_upload_media', [
            'site_id' => $this->site()->id,
            'filename' => 'banner.jpg',
            'url' => 'https://cdn.example.com/banner.jpg',
        ]);

        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('alt', $result['content']);
        $this->assertSame(0, PendingAction::count());
    }

    /** PDF אינו תמונה ולכן אינו נדרש לטקסט חלופי. */
    public function test_a_pdf_does_not_need_alt_text(): void
    {
        $result = $this->tool('propose_upload_media', [
            'site_id' => $this->site()->id,
            'filename' => 'מחירון.pdf',
            'url' => 'https://cdn.example.com/prices.pdf',
        ]);

        $this->assertArrayNotHasKey('is_error', $result);
        $this->assertSame('wp_media_upload', PendingAction::sole()->payload['tool']);
    }

    /** ההצעה אומרת שלהעלאה אין ביטול — כי אין. */
    public function test_the_upload_approval_says_it_cannot_be_undone(): void
    {
        $this->tool('propose_upload_media', [
            'site_id' => $this->site()->id,
            'filename' => 'banner.jpg',
            'url' => 'https://cdn.example.com/banner.jpg',
            'alt' => 'חנות הדגל ברחוב הרצל',
        ]);

        $this->assertStringContainsString('אין ביטול', PendingAction::sole()->summary);
    }

    /** כתובת שאינה http(s) נדחית. */
    public function test_a_non_http_source_is_refused(): void
    {
        $result = $this->tool('propose_upload_media', [
            'site_id' => $this->site()->id,
            'filename' => 'x.jpg',
            'url' => 'file:///etc/passwd',
            'alt' => 'לא',
        ]);

        $this->assertTrue($result['is_error']);
        $this->assertSame(0, PendingAction::count());
    }

    /**
     * הסרת תמונה ראשית היא הוראה, לא שדה חסר.
     *
     * attachment_id=0 פירושו "הסר"; קריאה שלו כ"לא צוין" הייתה הופכת בקשה
     * מפורשת לשגיאה.
     */
    public function test_clearing_a_featured_image_is_an_instruction_not_a_missing_field(): void
    {
        $result = $this->tool('propose_set_featured_image', [
            'site_id' => $this->site()->id, 'id' => 9, 'attachment_id' => 0,
        ]);

        $this->assertArrayNotHasKey('is_error', $result);
        $this->assertStringContainsString('הסרת התמונה הראשית', PendingAction::sole()->summary);
    }

    // ---- undo ---------------------------------------------------------------

    /** שינוי תפקיד ניתן לביטול — חזרה לתפקיד שהיה. */
    public function test_a_role_change_can_be_undone(): void
    {
        $recipe = app(RevertRecipe::class)->for(
            'wp_user_role_set',
            ['user_id' => 12, 'role' => 'author'],
            (string) json_encode(['user_id' => 12, 'role' => 'author', 'changed' => true, 'previous' => ['role' => 'subscriber']]),
        );

        $this->assertSame([
            'tool' => 'wp_user_role_set',
            'arguments' => ['user_id' => 12, 'role' => 'subscriber'],
        ], $recipe);
    }

    /**
     * וביטול של תמונה ראשית מחזיר גם "לא הייתה כזו".
     *
     * 0 הוא ערך קודם אמיתי. התייחסות אליו כ"אין מה לשחזר" הייתה משאירה תמונה
     * ראשית שאיש לא ביקש על עמוד שלא הייתה לו אחת.
     */
    public function test_undoing_a_featured_image_restores_having_had_none(): void
    {
        $recipe = app(RevertRecipe::class)->for(
            'wp_post_thumbnail_set',
            ['id' => 9, 'attachment_id' => 55],
            (string) json_encode(['id' => 9, 'attachment_id' => 55, 'previous' => ['attachment_id' => 0]]),
        );

        $this->assertSame([
            'tool' => 'wp_post_thumbnail_set',
            'arguments' => ['id' => 9, 'attachment_id' => 0],
        ], $recipe);
    }

    // ---- risk tiers ---------------------------------------------------------

    /**
     * הקריאות הן קריאות, והכתיבות דורשות אישור.
     *
     * כלי כתיבה שנופל בטעות למדרגה 0 היה רץ בלי אישור בכלל — ולכן זה נבדק
     * ולא מונח.
     */
    public function test_the_new_tools_land_on_the_right_risk_tiers(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $site = $this->site();

        foreach (['wp_user_list', 'wp_media_list'] as $tool) {
            $this->assertSame(0, $catalog->resolveTier($site, $tool), "{$tool} should be a tier-0 read");
        }

        foreach (['wp_user_create', 'wp_user_role_set', 'wp_media_upload', 'wp_post_thumbnail_set'] as $tool) {
            $this->assertSame(2, $catalog->resolveTier($site, $tool),
                "{$tool} changes the site and must require an approval");
        }
    }
}
