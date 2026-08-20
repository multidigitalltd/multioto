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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * מודרציה של תגובות, קטגוריות, ותזמון פרסום.
 *
 * שלושתם חולקים אותו כשל אפשרי — פעולה שמדווחת הצלחה ועושה משהו אחר:
 *
 * שיוך לקטגוריה בשם שגוי אינו נכשל אצל וורדפרס, הוא **יוצר** קטגוריה שנייה
 * עם אותו שם בערך — פיצול שקט של הטקסונומיה. תזמון לתאריך שעבר אינו נדחה, הוא
 * מתפרסם מיד. ותגובה שנכתבה על ידי זר עלולה להיות מנוסחת כהוראה לסוכן.
 */
class SiteCommentAndTermToolsTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.co.il/wp-json/md-agent/v1/mcp',
        ]);
    }

    /** @param  array<string, mixed>  $input */
    private function tool(string $tool, array $input): array
    {
        $agent = new ConsoleAgent(
            $this->createMock(ClaudeClient::class),
            app(ApprovalGate::class),
            $this->createMock(McpClient::class),
        );

        $method = new \ReflectionMethod($agent, 'handle');
        $method->setAccessible(true);

        return (array) $method->invoke($agent, $tool, $input);
    }

    // ---- comments -----------------------------------------------------------

    /** מודרציה מוצעת עם ציטוט, כדי שהמנהל יאשר על סמך מה שכתוב. */
    public function test_moderating_a_comment_is_proposed_with_its_reason(): void
    {
        $site = $this->site();

        $result = $this->tool('propose_moderate_comment', [
            'site_id' => $site->id,
            'comment_id' => 88,
            'status' => 'spam',
            'note' => 'קישורים לאתר הימורים: "בואו לזכות עכשיו…"',
        ]);

        $this->assertArrayNotHasKey('is_error', $result);

        $action = PendingAction::sole();
        $this->assertSame('wp_comment_moderate', $action->payload['tool']);
        $this->assertSame(['comment_id' => 88, 'status' => 'spam'], $action->payload['arguments']);
        $this->assertStringContainsString('ספאם', $action->summary);
        $this->assertStringContainsString('הימורים', $action->summary);
    }

    /** מחיקה סופית אינה מצב שאפשר לבקש — רק פח, שהוא הפיך. */
    public function test_deleting_a_comment_is_not_a_status_the_agent_can_ask_for(): void
    {
        foreach (['delete', 'destroy', ''] as $status) {
            $result = $this->tool('propose_moderate_comment', [
                'site_id' => $this->site()->id, 'comment_id' => 88, 'status' => $status,
            ]);

            $this->assertTrue($result['is_error'], "status '{$status}' should be refused");
        }

        $this->assertSame(0, PendingAction::count());
    }

    /** ומודרציה ניתנת לביטול — חזרה למצב שהתגובה הייתה בו. */
    public function test_moderation_can_be_undone(): void
    {
        $recipe = app(RevertRecipe::class)->for(
            'wp_comment_moderate',
            ['comment_id' => 88, 'status' => 'spam'],
            (string) json_encode(['comment_id' => 88, 'status' => 'spam', 'changed' => true, 'previous' => ['status' => 'hold']]),
        );

        $this->assertSame([
            'tool' => 'wp_comment_moderate',
            'arguments' => ['comment_id' => 88, 'status' => 'hold'],
        ], $recipe);
    }

    // ---- terms --------------------------------------------------------------

    /** שיוך ברירת מחדל מוסיף, ואומר זאת בהצעה. */
    public function test_assigning_terms_adds_by_default(): void
    {
        $site = $this->site();

        $this->tool('propose_set_post_terms', [
            'site_id' => $site->id, 'id' => 12, 'taxonomy' => 'category', 'term_ids' => [3, 7],
        ]);

        $action = PendingAction::sole();
        $this->assertSame('add', $action->payload['arguments']['mode']);
        $this->assertStringContainsString('הוספה לשיוך הקיים', $action->summary);
    }

    /** והחלפה אומרת בפירוש שהיא מסירה. */
    public function test_replacing_terms_warns_that_it_removes(): void
    {
        $this->tool('propose_set_post_terms', [
            'site_id' => $this->site()->id, 'id' => 12, 'taxonomy' => 'category',
            'term_ids' => [3], 'mode' => 'replace',
        ]);

        $this->assertStringContainsString('יוסר', PendingAction::sole()->summary);
    }

    /**
     * שיוך בלי מונחים במצב "הוספה" אינו הצעה — הוא פעולה שלא עושה דבר.
     *
     * במצב החלפה זו כן הוראה: "אל תשייך לשום קטגוריה".
     */
    public function test_an_empty_add_is_refused_but_an_empty_replace_is_an_instruction(): void
    {
        $site = $this->site();

        $refused = $this->tool('propose_set_post_terms', [
            'site_id' => $site->id, 'id' => 12, 'taxonomy' => 'category', 'term_ids' => [],
        ]);

        $this->assertTrue($refused['is_error']);
        $this->assertSame(0, PendingAction::count());

        $accepted = $this->tool('propose_set_post_terms', [
            'site_id' => $site->id, 'id' => 12, 'taxonomy' => 'category', 'term_ids' => [], 'mode' => 'replace',
        ]);

        $this->assertArrayNotHasKey('is_error', $accepted);
        $this->assertSame(1, PendingAction::count());
    }

    /**
     * ביטול של שיוך מחזיר את הרשימה כפי שהייתה — גם כשההוספה רק הוסיפה.
     *
     * לכן ה-previous נושא mode=replace: ביטול של "הוסף" הוא החזרת הקבוצה
     * למצבה, וזה מה שרק החלפה יודעת לעשות.
     */
    public function test_undoing_an_assignment_puts_the_whole_set_back(): void
    {
        $recipe = app(RevertRecipe::class)->for(
            'wp_post_terms_set',
            ['id' => 12, 'taxonomy' => 'category', 'term_ids' => [3, 7], 'mode' => 'add'],
            (string) json_encode([
                'id' => 12,
                'taxonomy' => 'category',
                'term_ids' => [1, 3, 7],
                'previous' => ['term_ids' => [1], 'mode' => 'replace'],
            ]),
        );

        $this->assertSame([
            'tool' => 'wp_post_terms_set',
            'arguments' => ['id' => 12, 'taxonomy' => 'category', 'term_ids' => [1], 'mode' => 'replace'],
        ], $recipe);
    }

    // ---- scheduling ---------------------------------------------------------

    /** תזמון לאחור נדחה לפני שמישהו מתבקש לאשר אותו. */
    public function test_scheduling_backwards_is_refused_before_it_is_proposed(): void
    {
        $result = $this->tool('propose_content_edit', [
            'site_id' => $this->site()->id,
            'id' => 5,
            'publish_at' => Carbon::now()->subDay()->format('Y-m-d H:i'),
        ]);

        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('בעתיד', $result['content']);
        $this->assertSame(0, PendingAction::count());
    }

    /** ותאריך שאינו תאריך בכלל נדחה גם הוא. */
    public function test_a_date_that_is_not_a_date_is_refused(): void
    {
        $result = $this->tool('propose_content_edit', [
            'site_id' => $this->site()->id, 'id' => 5, 'publish_at' => 'בקרוב',
        ]);

        $this->assertTrue($result['is_error']);
        $this->assertSame(0, PendingAction::count());
    }

    // ---- risk tiers ---------------------------------------------------------

    public function test_the_new_tools_land_on_the_right_risk_tiers(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $site = $this->site();

        foreach (['wp_comment_list', 'wp_taxonomy_list', 'wp_term_list'] as $tool) {
            $this->assertSame(0, $catalog->resolveTier($site, $tool), "{$tool} should be a tier-0 read");
        }

        foreach (['wp_comment_moderate', 'wp_term_create', 'wp_post_terms_set'] as $tool) {
            $this->assertSame(2, $catalog->resolveTier($site, $tool),
                "{$tool} changes the site and must require an approval");
        }
    }
}
