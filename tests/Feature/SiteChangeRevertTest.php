<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteChange;
use App\Services\Agent\SiteActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * שינוי שבוצע דרך הסוכן ניתן לשחזור — בלי שמישהו הכין את השחזור מראש.
 *
 * "שחזר" קיים בפאנל מזמן, אבל הוא מופיע רק כשליומן יש קריאה הפוכה שמורה, וכלי
 * התוכן והמחירים החדשים לא ידעו לייצר אחת. הבדיקה הזו סוגרת את הפער מקצה
 * לקצה: פעולה שאושרה ובוצעה משאירה אחריה דרך חזרה, שנגזרת ממה שהאתר באמת החזיר.
 */
class SiteChangeRevertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.actions_enabled' => true]);
    }

    private function site(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example-site.co.il/wp-json/md-agent/mcp',
            'mcp_secret' => 'site-secret',
        ]);
    }

    private function fakeToolOutput(string $text): void
    {
        Http::fake([
            'example-site.co.il/*' => function (Request $request) use ($text) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false],
                ]);
            },
        ]);
    }

    private function execute(Site $site, string $tool, array $arguments): void
    {
        app(SiteActionRunner::class)->run(PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Approved,
            'customer_id' => $site->customer_id,
            'summary' => "פעולת AI: {$tool}",
            'payload' => ['site_id' => $site->id, 'tool' => $tool, 'arguments' => $arguments],
            'proposed_by' => 'ai',
        ]));
    }

    /** שינוי מחיר משאיר ביומן דרך חזרה למחיר הקודם. */
    public function test_a_price_change_leaves_a_way_back(): void
    {
        $site = $this->site();
        $this->fakeToolOutput(json_encode([
            'updated_id' => 55,
            'changed' => ['regular_price' => '89'],
            'previous' => ['regular_price' => '79', 'stock_quantity' => 4],
        ]));

        $this->execute($site, 'wc_product_update', ['product_id' => 55, 'regular_price' => '89']);

        $change = SiteChange::where('site_id', $site->id)->sole();

        $this->assertSame('wc_product_update', $change->revert_tool);
        $this->assertSame(['product_id' => 55, 'regular_price' => '79'], $change->revert_arguments);
    }

    /** וכך גם עריכת טקסט בעמוד. */
    public function test_a_content_edit_leaves_a_way_back(): void
    {
        $site = $this->site();
        $this->fakeToolOutput(json_encode([
            'updated_id' => 12,
            'previous' => ['title' => 'כותרת ישנה'],
        ]));

        $this->execute($site, 'wp_content_update', ['id' => 12, 'title' => 'כותרת חדשה']);

        $change = SiteChange::where('site_id', $site->id)->sole();

        $this->assertSame('wp_content_update', $change->revert_tool);
        $this->assertSame('כותרת ישנה', $change->revert_arguments['title']);
    }

    /**
     * מתכון שנשלח במפורש בהצעה גובר על הנגזר.
     *
     * מי שיודע לבטל את הפעולה שלו יודע טוב יותר מכלל כללי — ואם הנגזר היה דורס
     * אותו, פעולה מורכבת הייתה מבוטלת חלקית.
     */
    public function test_an_explicit_recipe_wins_over_the_derived_one(): void
    {
        $site = $this->site();
        $this->fakeToolOutput(json_encode(['updated_id' => 12, 'previous' => ['title' => 'ישן']]));

        app(SiteActionRunner::class)->run(PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Approved,
            'customer_id' => $site->customer_id,
            'summary' => 'פעולה עם מתכון משלה',
            'payload' => [
                'site_id' => $site->id,
                'tool' => 'wp_content_update',
                'arguments' => ['id' => 12, 'title' => 'חדש'],
                'revert' => ['tool' => 'wp_content_trash', 'arguments' => ['id' => 12]],
            ],
            'proposed_by' => 'ai',
        ]));

        $this->assertSame('wp_content_trash', SiteChange::where('site_id', $site->id)->sole()->revert_tool);
    }

    /** כלי שאינו מדווח מה החליף — נרשם ביומן בלי דרך חזרה, ולא עם דרך מנוחשת. */
    public function test_a_tool_that_reports_nothing_is_journalled_without_a_revert(): void
    {
        $site = $this->site();
        $this->fakeToolOutput('התוסף עודכן בהצלחה');

        $this->execute($site, 'wp_plugin_update', ['plugin' => 'akismet']);

        $change = SiteChange::where('site_id', $site->id)->sole();

        $this->assertNull($change->revert_tool);
    }

    /** ופעולת קריאה אינה נכנסת ליומן בכלל — אין מה לבטל, ואין מה להצף. */
    public function test_a_read_leaves_no_journal_entry(): void
    {
        $site = $this->site();
        $this->fakeToolOutput('[]');

        $this->execute($site, 'wc_product_search', ['search' => 'חולצה']);

        $this->assertSame(0, SiteChange::where('site_id', $site->id)->count());
    }
}
