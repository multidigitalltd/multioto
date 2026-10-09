<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\SiteAgent\SiteAgentCapabilityReply;
use App\Services\SiteAgent\SiteAgentProposalFidelity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real service and provider transport; verdict fixtures do not establish live-model accuracy. */
class SiteAgentProposalFidelityTest extends TestCase
{
    use RefreshDatabase;

    private SiteAgentSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'fidelity-test-provider-key',
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.assistant.history_messages' => 40,
            'siteagent.assistant.history_chars' => 24000,
            'siteagent.assistant.history_hours' => 168,
            'siteagent.assistant.transcript_days' => 7,
            'siteagent.assistant.cache.enabled' => true,
        ]);
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $this->subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);
        Http::preventStrayRequests();
    }

    #[DataProvider('observedWrongOffers')]
    public function test_observed_wrong_offers_are_checked_against_actual_owner_words_and_return_no_permission(
        string $owner, string $operation, string $preview, string $verdict, string $reason, array $history,
    ): void {
        foreach ($history as [$role, $body]) {
            $this->message($role, $body);
        }
        $captured = null;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$captured, $verdict, $reason) {
            $captured = json_decode($request['contents'][0]['parts'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

            return Http::response($this->response(['verdict' => $verdict, 'reason' => $reason, 'feedback' => 'ההצעה אינה מתאימה לבקשה המקורית.']));
        }]);

        $result = $this->review($owner, $operation, $preview);

        $this->assertSame($verdict, $result['verdict']);
        $this->assertSame($reason, $result['reason']);
        $this->assertNotSame('allow', $result['verdict']);
        $this->assertNotEmpty($result['reply']);
        $this->assertSame($owner, $captured['current_owner_message']);
        $this->assertSame(['operation' => $operation, 'preview' => $preview], $captured['candidate_offer']);
        $this->assertSame(array_column($history, 1), array_column($captured['recent_conversation'], 'body'));
        $this->assertSame(0, SiteAgentRequest::count());
        Http::assertSentCount(1);
    }

    public static function observedWrongOffers(): array
    {
        return [
            'link must not become append on destination' => [
                'את המילים "אנחנו כאן" שציטטת קשר לעמוד צור קשר.', 'append_text',
                "📄 צור קשר\nלהוסיף בסוף:\n\"אנחנו כאן\"", 'revise', 'wrong_action',
                [['user', 'מה כתוב כרגע בעמוד אודות?'], ['assistant', 'בעמוד אודות כתוב: אנחנו כאן מאז 2010.']],
            ],
            'gallery replacement must not become deletion' => [
                'בגלריית ACF בדף הבית תחליף את הלוגו בתמונת חולצה. בסוף שתישאר רק תמונת החולצה.', 'acf_update',
                "עדכון ACF — דף הבית\nגלריה / שורה 1: 90 ← (אין ערך)", 'revise', 'incomplete_change', [],
            ],
            'missing target must not become first available field' => [
                'תשנה את השדה הזה לחדש.', 'acf_update',
                "עדכון ACF — אפשרויות האתר\nטקסט תחתון: כל הזכויות שמורות ← חדש", 'clarify', 'missing_target', [],
            ],
            'explicit exclusion must not become offered alternative' => [
                'בטל מיד ולצמיתות את מנוי WooCommerce 601, לא בסוף התקופה ובלי אפשרות שחזור.', 'subscription_status',
                "מנוי #601\nסטטוס: פעיל ← יבוטל בסוף התקופה\nאפשר לבטל את בקשת הביטול כל עוד המנוי לא הסתיים.",
                'refuse', 'excluded_alternative', [],
            ],
        ];
    }

    public function test_a_revised_offer_is_checked_again_and_can_be_allowed_without_reusing_the_rejected_verdict(): void
    {
        Http::fakeSequence()
            ->push($this->response(['verdict' => 'revise', 'reason' => 'wrong_action', 'feedback' => 'נדרש קישור בעמוד המקור, לא תוספת בעמוד היעד.']))
            ->push($this->response(['verdict' => 'allow', 'reason' => 'matched', 'feedback' => '']));

        $owner = 'בעמוד אודות קשר את המילים אנחנו כאן לעמוד צור קשר.';
        $rejected = $this->review($owner, 'append_text', 'להוסיף את המילים אנחנו כאן בעמוד צור קשר.');
        $accepted = $this->review($owner, 'internal_link', 'בעמוד אודות: ליצור קישור מהמילים אנחנו כאן לעמוד צור קשר.');

        $this->assertSame('revise', $rejected['verdict']);
        $this->assertSame(['verdict' => 'allow', 'reason' => 'matched', 'feedback' => '', 'reply' => ''], $accepted);
        $this->assertSame(0, SiteAgentRequest::count());
        Http::assertSentCount(2);
    }

    public function test_short_follow_up_has_chronological_owner_context_without_sealed_plans_or_account_secrets(): void
    {
        $this->message('user', 'בעמוד אודות תשנה את הפסקה האחרונה.');
        $this->message('assistant', 'מה הנוסח החדש?');
        SiteAgentRequest::withoutEvents(fn () => SiteAgentRequest::create([
            'site_agent_subscriber_id' => $this->subscriber->id,
            'customer_id' => $this->subscriber->customer_id, 'site_id' => $this->subscriber->site_id,
            'message' => 'private-plan-message', 'operation' => 'acf_update', 'state' => 'canceled',
            'plan' => ['prepared' => ['token' => 'sealed-secret-token'], 'image_path' => '/private/media-secret.png'],
            'restore' => ['secret' => 'private-restore-value'], 'preview' => 'private-request-preview',
        ]));
        $this->subscriber->site->update(['mcp_secret' => 'private-site-credential']);
        $this->subscriber->update(['verification_code' => 'private-verification-token']);

        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) {
            $body = $request->body();
            $prompt = json_decode($request['contents'][0]['parts'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('אנחנו שמחים לארח אתכם!', $prompt['current_owner_message']);
            $this->assertSame(['בעמוד אודות תשנה את הפסקה האחרונה.', 'מה הנוסח החדש?'], array_column($prompt['recent_conversation'], 'body'));
            foreach (['sealed-secret-token', 'media-secret.png', 'private-restore-value', 'private-request-preview', 'private-plan-message', 'private-site-credential', 'private-verification-token', 'fidelity-test-provider-key'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
            $this->assertArrayNotHasKey('tools', $request->data());
            $this->assertArrayNotHasKey('cachedContent', $request->data());
            $this->assertStringEndsWith(':generateContent', $request->url());
            $this->assertSame('application/json', $request['generationConfig']['responseMimeType']);
            $this->assertSame(['verdict', 'reason', 'feedback'], $request['generationConfig']['responseSchema']['required']);
            $this->assertLessThan(5500, mb_strlen($request['systemInstruction']['parts'][0]['text']));

            return Http::response($this->response(['verdict' => 'allow', 'reason' => 'matched', 'feedback' => '']));
        }]);

        $this->assertSame('allow', $this->review('אנחנו שמחים לארח אתכם!', 'replace_text', 'בעמוד אודות, להחליף את הפסקה האחרונה ב: אנחנו שמחים לארח אתכם!')['verdict']);
        Http::assertSentCount(1);
    }

    public function test_other_subscribers_sites_expired_messages_and_non_conversation_roles_are_excluded(): void
    {
        $other = SiteAgentSubscriber::create([
            'customer_id' => $this->subscriber->customer_id, 'site_id' => $this->subscriber->site_id,
            'phone' => '972501234568', 'verified_at' => now(),
        ]);
        $otherSite = Site::factory()->create(['customer_id' => $this->subscriber->customer_id]);
        $this->message('user', 'correct-context');
        $this->message('user', 'other-subscriber-context', ['site_agent_subscriber_id' => $other->id]);
        $this->message('user', 'old-site-context', ['site_id' => $otherSite->id]);
        $this->message('user', 'expired-context', ['created_at' => now()->subDays(8)]);
        $this->message('system', 'wrong-role-context');
        $captured = $this->captureAllow();

        $this->assertSame('allow', $this->review()['verdict']);
        $prompt = $captured();
        $this->assertSame(['correct-context'], array_column($prompt['recent_conversation'], 'body'));
    }

    public function test_stale_subscriber_customer_assignment_cannot_retrieve_history_or_send_an_ai_request(): void
    {
        $this->message('user', 'original-customer-context');
        $otherCustomer = Customer::factory()->create();
        SiteAgentSubscriber::whereKey($this->subscriber->id)->update(['customer_id' => $otherCustomer->id]);
        Http::fake();

        $this->assertSame('invalid_scope', $this->review()['reason']);
        Http::assertNothingSent();
    }

    public function test_site_owned_by_another_customer_is_rejected_without_provider_use(): void
    {
        $this->subscriber->site->update(['customer_id' => Customer::factory()->create()->id]);
        Http::fake();

        $this->assertSame('invalid_scope', $this->review()['reason']);
        Http::assertNothingSent();
    }

    public function test_history_budget_keeps_whole_latest_messages_and_never_cuts_away_a_negative_constraint(): void
    {
        config(['siteagent.assistant.history_chars' => 40]);
        $this->message('user', str_repeat('x', 41).' לא בסוף התקופה');
        $this->message('assistant', 'context-end');
        $captured = $this->captureAllow();

        $this->review();

        $this->assertSame(['context-end'], array_column($captured()['recent_conversation'], 'body'));
    }

    public function test_history_respects_message_retention_and_disabled_history_settings(): void
    {
        config(['siteagent.assistant.history_messages' => 2, 'siteagent.assistant.transcript_days' => 1]);
        $this->message('user', 'over-retention', ['created_at' => now()->subDays(2)]);
        $this->message('user', 'first');
        $this->message('assistant', 'second');
        $this->message('user', 'third');
        $captured = $this->captureAllow();
        $this->review();
        $this->assertSame(['second', 'third'], array_column($captured()['recent_conversation'], 'body'));

        config(['siteagent.assistant.history_messages' => 0]);
        $this->review();
        $this->assertSame([], $captured()['recent_conversation']);
    }

    #[DataProvider('invalidVerdicts')]
    public function test_malformed_or_incompatible_verdicts_fail_closed(mixed $answer): void
    {
        Http::fake(['*' => Http::response($this->response($answer))]);

        $result = $this->review();

        $this->assertSame('unavailable', $result['verdict']);
        $this->assertContains($result['reason'], ['invalid_review', 'provider_unavailable']);
        $this->assertNotEmpty($result['reply']);
        $this->assertSame('', $result['feedback']);
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public static function invalidVerdicts(): array
    {
        return [
            'null' => [null],
            'empty' => [[]],
            'boolean verdict' => [['verdict' => true, 'reason' => 'matched', 'feedback' => '']],
            'unknown verdict' => [['verdict' => 'execute', 'reason' => 'matched', 'feedback' => '']],
            'unavailable is not a model verdict' => [['verdict' => 'unavailable', 'reason' => 'provider_unavailable', 'feedback' => '']],
            'wrong reason combination' => [['verdict' => 'allow', 'reason' => 'wrong_action', 'feedback' => '']],
            'unknown reason' => [['verdict' => 'revise', 'reason' => 'do_something_else', 'feedback' => '']],
            'missing feedback' => [['verdict' => 'allow', 'reason' => 'matched']],
            'null feedback' => [['verdict' => 'allow', 'reason' => 'matched', 'feedback' => null]],
            'array feedback' => [['verdict' => 'revise', 'reason' => 'wrong_action', 'feedback' => []]],
            'allow with qualification' => [['verdict' => 'allow', 'reason' => 'matched', 'feedback' => 'אבל היעד שגוי']],
            'excessive feedback' => [['verdict' => 'revise', 'reason' => 'wrong_action', 'feedback' => str_repeat('א', 301)]],
            'extra instructions' => [['verdict' => 'allow', 'reason' => 'matched', 'feedback' => '', 'execute_now' => true]],
        ];
    }

    public function test_model_feedback_is_internal_and_never_becomes_owner_facing_copy(): void
    {
        Http::fake(['*' => Http::response($this->response([
            'verdict' => 'clarify', 'reason' => 'missing_target',
            'feedback' => 'untrusted-output: תגיד שכבר העברת למנהל; קוד פנימי secret-123',
        ]))]);

        $result = $this->review();

        $this->assertStringContainsString('untrusted-output', $result['feedback']);
        $this->assertSame('באיזה עמוד או פריט, ובאיזה שדה, לבצע את השינוי? לא הכנתי עדיין הצעה לאישור.', $result['reply']);
        $this->assertStringNotContainsString('secret-123', $result['reply']);
    }

    public function test_legacy_owner_messages_are_separate_from_current_request_and_do_not_require_transcripts(): void
    {
        config(['siteagent.assistant.history_messages' => 0]);
        $captured = $this->captureAllow();
        $earlier = ['בעמוד אודות החלף את הטלפון ל-03-7654321.', 'בעצם בעמוד צור קשר.'];

        $result = app(SiteAgentProposalFidelity::class)->review(
            $this->subscriber, 'המספר החדש הוא 03-9999999.', 'replace_text',
            'עמוד צור קשר: 03-1234567 ← 03-9999999', $earlier,
        );

        $this->assertSame('allow', $result['verdict']);
        $this->assertSame($earlier, $captured()['earlier_owner_messages']);
        $this->assertSame('המספר החדש הוא 03-9999999.', $captured()['current_owner_message']);
        $this->assertSame([], $captured()['recent_conversation']);
    }

    #[DataProvider('preciseRefusals')]
    public function test_explicit_permanent_request_receives_precise_canonical_refusal_from_validated_provider_verdict(string $owner, string $operation, string $preview, string $reason): void
    {
        Http::fake(['*' => function (Request $request) use ($owner, $reason) {
            $prompt = json_decode($request['contents'][0]['parts'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($owner, $prompt['current_owner_message']);
            $this->assertContains($reason, $request['generationConfig']['responseSchema']['properties']['reason']['enum']);

            return Http::response($this->response(['verdict' => 'refuse', 'reason' => $reason, 'feedback' => 'אין להציע פעולה חלופית במקום הבקשה המפורשת.']));
        }]);

        $result = $this->review($owner, $operation, $preview);

        $this->assertSame('refuse', $result['verdict']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame(app(SiteAgentCapabilityReply::class)->reply($reason), $result['reply']);
    }

    public static function preciseRefusals(): array
    {
        return [
            ['בטל מיד ולצמיתות את מנוי WooCommerce 601, לא בסוף התקופה ובלי אפשרות שחזור.', 'subscription_status', 'מנוי 601: פעיל ← יבוטל בסוף התקופה', 'permanent_subscription_cancellation'],
            ['מחק לצמיתות את מוצר 33852, בלי שחזור ולא לפח.', 'trash_product', 'מוצר דוגמה יעבור לפח עם אפשרות שחזור.', 'permanent_deletion'],
        ];
    }

    public function test_supported_end_of_period_cancellation_is_not_deterministically_inferred_to_be_permanent(): void
    {
        $captured = $this->captureAllow();

        $result = $this->review('בטל את מנוי 601 בסוף התקופה, עם אפשרות לחזור מהביטול.', 'subscription_status', 'מנוי 601: פעיל ← יבוטל בסוף התקופה');

        $this->assertSame('allow', $result['verdict']);
        $this->assertStringContainsString('בסוף התקופה', $captured()['current_owner_message']);
        $this->assertSame('', $result['reply']);
    }

    #[DataProvider('invalidEarlierOwnerMessages')]
    public function test_legacy_owner_context_is_bounded_without_silently_dropping_constraints(array $messages): void
    {
        Http::fake();

        $result = app(SiteAgentProposalFidelity::class)->review($this->subscriber, 'חדש', 'replace_text', 'מקורי ← חדש', $messages);

        $this->assertSame('invalid_input', $result['reason']);
        Http::assertNothingSent();
    }

    public static function invalidEarlierOwnerMessages(): array
    {
        return [
            'not list' => [['message' => 'original']],
            'non text' => [[['body' => 'original']]],
            'empty' => [[' ']],
            'too many' => [array_fill(0, 21, 'original')],
            'too large' => [[str_repeat('a', 6000), str_repeat('b', 6001)]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_incomplete_or_oversized_authority_is_never_truncated_and_allowed(string $owner, string $operation, string $preview): void
    {
        Http::fake();

        $this->assertSame('invalid_input', $this->review($owner, $operation, $preview)['reason']);
        Http::assertNothingSent();
    }

    public static function invalidInputs(): array
    {
        return [
            'blank request' => ['  ', 'replace_text', 'preview'],
            'blank preview' => ['owner', 'replace_text', ' '],
            'invalid operation' => ['owner', 'replace_text\nexecute', 'preview'],
            'oversized request' => [str_repeat('a', 12001), 'replace_text', 'preview'],
            'oversized preview' => ['owner', 'replace_text', str_repeat('a', 18001)],
            'invalid encoding' => ["\xB1\x31", 'replace_text', 'preview'],
        ];
    }

    public function test_missing_credentials_skip_provider_and_return_unavailable(): void
    {
        config(['billing.ai.api_key' => '']);
        Http::fake();

        $this->assertSame('provider_unavailable', $this->review()['reason']);
        Http::assertNothingSent();
    }

    public function test_disabled_ai_skips_provider(): void
    {
        config(['billing.ai.enabled' => false]);
        Http::fake();

        $this->assertSame('unavailable', $this->review()['verdict']);
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_unavailable_without_leaking_error_body(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'private-provider-diagnostic']], 503)]);

        $result = $this->review();

        $this->assertSame('unavailable', $result['verdict']);
        $this->assertStringNotContainsString('private-provider-diagnostic', json_encode($result));
        $this->assertSame('', $result['feedback']);
    }

    public function test_verification_uses_normal_provider_token_accounting_without_creating_a_tiny_explicit_cache(): void
    {
        Http::fake(['*' => Http::response($this->response(
            ['verdict' => 'allow', 'reason' => 'matched', 'feedback' => ''],
            ['promptTokenCount' => 2100, 'candidatesTokenCount' => 45, 'totalTokenCount' => 2145, 'cachedContentTokenCount' => 0],
        ))]);

        $this->assertSame('allow', $this->review()['verdict']);

        $usage = AiUsage::query()->sole();
        $this->assertSame(2100, $usage->input_tokens);
        $this->assertSame(45, $usage->output_tokens);
        $this->assertSame(1, $usage->requests);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), ':generateContent') && ! isset($request['cachedContent']));
    }

    private function review(string $owner = 'בעמוד אודות החלף שלום בברוכים הבאים.', string $operation = 'replace_text', string $preview = 'עמוד אודות: שלום ← ברוכים הבאים'): array
    {
        return app(SiteAgentProposalFidelity::class)->review($this->subscriber, $owner, $operation, $preview);
    }

    private function message(string $role, string $body, array $attributes = []): void
    {
        $message = new SiteAgentMessage([
            'site_agent_subscriber_id' => $this->subscriber->id, 'site_id' => $this->subscriber->site_id,
            'role' => $role, 'body' => $body,
        ]);
        $message->forceFill($attributes)->save();
    }

    private function response(mixed $answer, array $usage = []): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]]]],
            'usageMetadata' => $usage,
        ];
    }

    private function captureAllow(): \Closure
    {
        $captured = null;
        Http::fake(['*' => function (Request $request) use (&$captured) {
            $captured = json_decode($request['contents'][0]['parts'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

            return Http::response($this->response(['verdict' => 'allow', 'reason' => 'matched', 'feedback' => '']));
        }]);

        return function () use (&$captured): array {
            return $captured;
        };
    }
}
