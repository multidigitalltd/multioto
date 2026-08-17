<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteChange;
use App\Services\Agent\SiteActionBatchRunner;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * מבצע על עשרים מוצרים הוא החלטה אחת, לא עשרים.
 *
 * "תוריד 20% על כל החולצות עד סוף החודש" היא החלטה עסקית אחת שנוגעת בעשרים
 * מוצרים. לבקש מהבעלים לאשר אותה עשרים פעם הופך אישור שקול לרפלקס — וכך בדיוק
 * ההצעה העשרים ואחת עוברת בלי שקראו אותה. אותו דבר בכיוון חזרה: ביטול מוצר-מוצר
 * משאיר חנות חצי-במבצע כל עוד מישהו לוחץ.
 */
class SiteActionBatchTest extends TestCase
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

    /** @param  callable(array): array  $responder */
    private function fakeSite(callable $responder): void
    {
        Http::fake([
            'example-site.co.il/*' => function (Request $request) use ($responder) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                $args = $body['params']['arguments'] ?? [];
                $result = $responder($args);

                if (($result['fail'] ?? false) === true) {
                    return Http::response(['jsonrpc' => '2.0', 'id' => $body['id'], 'error' => [
                        'code' => -32000, 'message' => 'המוצר לא נמצא.',
                    ]]);
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => (string) $result['text']]], 'isError' => false],
                ]);
            },
        ]);
    }

    /** @param  list<array<string, mixed>>  $calls */
    private function batch(Site $site, array $calls): PendingAction
    {
        return PendingAction::create([
            'type' => 'site_action_batch',
            'status' => ActionStatus::Approved,
            'customer_id' => $site->customer_id,
            'summary' => 'מבצע 20% על החולצות',
            'payload' => ['site_id' => $site->id, 'calls' => $calls],
            'proposed_by' => 'ai',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function threeProducts(): array
    {
        return [
            ['tool' => 'wc_product_update', 'label' => 'חולצה שחורה', 'arguments' => ['product_id' => 1, 'sale_price' => '79']],
            ['tool' => 'wc_product_update', 'label' => 'חולצה לבנה', 'arguments' => ['product_id' => 2, 'sale_price' => '79']],
            ['tool' => 'wc_product_update', 'label' => 'חולצה כחולה', 'arguments' => ['product_id' => 3, 'sale_price' => '79']],
        ];
    }

    private function priceResponse(array $args): array
    {
        $id = (int) ($args['product_id'] ?? 0);

        return ['text' => (string) json_encode([
            'updated_id' => $id,
            'changed' => ['sale_price' => '79'],
            'previous' => ['sale_price' => null, 'regular_price' => '99'],
        ])];
    }

    /** אישור אחד מפעיל את כל המוצרים, וכל אחד נרשם ביומן בנפרד. */
    public function test_one_approval_applies_every_product(): void
    {
        $site = $this->site();
        $this->fakeSite(fn (array $args): array => $this->priceResponse($args));

        $report = app(SiteActionBatchRunner::class)->run($this->batch($site, $this->threeProducts()));

        $this->assertSame(3, SiteChange::where('site_id', $site->id)->count());
        $this->assertStringContainsString('חולצה כחולה', $report);
    }

    /** והשינויים קשורים זה לזה דרך האישור שהוליד אותם — בלי עמודה חדשה. */
    public function test_the_changes_are_grouped_by_the_approval_that_made_them(): void
    {
        $site = $this->site();
        $this->fakeSite(fn (array $args): array => $this->priceResponse($args));
        $action = $this->batch($site, $this->threeProducts());

        app(SiteActionBatchRunner::class)->run($action);

        $this->assertSame(3, SiteChange::where('pending_action_id', $action->id)->count());
        $this->assertSame(3, SiteChange::where('pending_action_id', $action->id)->first()->batchSize());
    }

    /**
     * כשל באמצע עוצר את השאר ואומר בדיוק היכן.
     *
     * המשך אחרי כשל היה מפזר תקלה לא מובנת על שאר החנות לפני שמישהו הסתכל על
     * הראשונה. מה שכבר בוצע רשום עם דרך חזרה משלו — מה שאסור לתת לבעלים זה
     * מבצע חצי-מיושם שמדווח כהצלחה.
     */
    public function test_a_failure_stops_the_batch_and_says_where(): void
    {
        $site = $this->site();
        $this->fakeSite(function (array $args): array {
            return (int) ($args['product_id'] ?? 0) === 2
                ? ['fail' => true]
                : $this->priceResponse($args);
        });

        try {
            app(SiteActionBatchRunner::class)->run($this->batch($site, $this->threeProducts()));
            $this->fail('האצווה הייתה אמורה להיעצר.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('חולצה לבנה', $e->getMessage());
            $this->assertStringContainsString('2 מתוך 3', $e->getMessage());
        }

        // The third product was never touched, and the first is recoverable.
        $this->assertSame(1, SiteChange::where('site_id', $site->id)->where('status', 'applied')->count());
    }

    /** ביטול האצווה מוצע בסדר הפוך, ורק למה שיש לו דרך חזרה. */
    public function test_the_revert_plan_runs_backwards_and_skips_what_it_cannot_undo(): void
    {
        $site = $this->site();
        $this->fakeSite(fn (array $args): array => $this->priceResponse($args));
        $action = $this->batch($site, $this->threeProducts());

        app(SiteActionBatchRunner::class)->run($action);

        // One of the three lost its recipe (an older change, a tool that never
        // reported what it replaced).
        SiteChange::where('pending_action_id', $action->id)->orderBy('id')->first()
            ->update(['revert_tool' => null, 'revert_arguments' => null]);

        $plan = app(SiteActionBatchRunner::class)->revertPlan($action);

        $this->assertCount(2, $plan['calls']);
        $this->assertSame(1, $plan['skipped']);
        // Reverse order: the last change applied is the first undone.
        $this->assertSame(3, $plan['calls'][0]['arguments']['product_id']);
        $this->assertSame('', $plan['calls'][0]['arguments']['sale_price']);
    }

    /** שינוי שכבר שוחזר אינו נכנס לתוכנית שוב — ביטול של ביטול היה מחזיר את המבצע. */
    public function test_an_already_reverted_change_is_not_undone_twice(): void
    {
        $site = $this->site();
        $this->fakeSite(fn (array $args): array => $this->priceResponse($args));
        $action = $this->batch($site, $this->threeProducts());

        app(SiteActionBatchRunner::class)->run($action);
        SiteChange::where('pending_action_id', $action->id)->orderBy('id')->first()->update(['reverted_at' => now()]);

        $this->assertCount(2, app(SiteActionBatchRunner::class)->revertPlan($action)['calls']);
    }

    /**
     * ואצווה לעולם אינה מקבלת אישור קבוע.
     *
     * "אשר תמיד" על אצווה היה מאשר גם את המבצע של החודש הבא, שאיש עוד לא כתב.
     */
    public function test_a_batch_can_never_get_a_standing_approval(): void
    {
        $this->assertNull(ApprovalGate::standingKeyFor('site_action_batch', [
            'site_id' => 1,
            'calls' => [['tool' => 'wc_product_update']],
        ]));
    }
}
