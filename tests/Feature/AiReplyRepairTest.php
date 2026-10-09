<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiReplyRepairTest extends TestCase
{
    use RefreshDatabase;

    private const BAD_REPLY = 'להחליף איזה כייף בכמה נחמד. לביצוע השיבו כן.';

    private const CORRECTION = 'Internal review: no proposal exists. Read the page and use propose_page_update before requesting approval.';

    private const FINAL_REPLY = 'ההצעה המאומתת מוכנה לאישור.';

    public static function providers(): array
    {
        return [['anthropic'], ['openai'], ['google']];
    }

    private function enable(string $provider): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => $provider,
            'billing.ai.model' => $provider === 'google' ? 'gemini-3.1-flash-lite' : 'test-model',
            'billing.ai.base_url' => 'https://provider.example',
            'siteagent.assistant.cache.enabled' => true,
            'siteagent.assistant.cache.ttl_minutes' => 60,
        ]);
        Http::preventStrayRequests();
    }

    private function tools(): array
    {
        return array_map(fn (string $name): array => [
            'name' => $name,
            'description' => $name,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ],
        ], ['read_page', 'propose_page_update']);
    }

    private function reply(string $provider, string $text): array
    {
        return match ($provider) {
            'openai' => ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]],
            'google' => ['candidates' => [['content' => ['parts' => [['text' => $text, 'thoughtSignature' => 'text-signature']]]]]],
            default => ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'thinking', 'thinking' => 'internal reasoning', 'signature' => 'text-signature'],
                ['type' => 'text', 'text' => $text],
            ]],
        };
    }

    private function toolResponse(string $provider, string $name): array
    {
        $arguments = ['id' => 8];

        return match ($provider) {
            'openai' => ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => $name.'-call', 'type' => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
            ]]]]]],
            'google' => ['candidates' => [['content' => ['parts' => [[
                'thoughtSignature' => $name.'-signature',
                'functionCall' => ['name' => $name, 'id' => $name.'-call', 'args' => $arguments],
            ]]]]]],
            default => ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'thinking', 'thinking' => 'internal reasoning', 'signature' => $name.'-signature'],
                ['type' => 'tool_use', 'id' => $name.'-call', 'name' => $name, 'input' => $arguments],
            ]],
        };
    }

    private function assertCorrection(Request $request, string $provider): void
    {
        $messages = $provider === 'google' ? $request['contents'] : $request['messages'];
        $last = $messages[array_key_last($messages)];
        $previous = $messages[count($messages) - 2];
        $this->assertSame('user', $last['role']);
        $this->assertSame(self::CORRECTION, $provider === 'google' ? $last['parts'][0]['text'] : $last['content']);
        $this->assertSame($provider === 'google' ? 'model' : 'assistant', $previous['role']);
        $this->assertStringContainsString(self::BAD_REPLY, json_encode($previous, JSON_UNESCAPED_UNICODE));
        if ($provider !== 'openai') {
            $this->assertStringContainsString('text-signature', json_encode($previous));
        }
    }

    #[DataProvider('providers')]
    public function test_unbacked_approval_is_repaired_with_reads_and_a_proposal_in_the_same_loop(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::sequence()
            ->push($this->reply($provider, self::BAD_REPLY))
            ->push($this->toolResponse($provider, 'read_page'))
            ->push($this->toolResponse($provider, 'propose_page_update'))
            ->push($this->reply($provider, self::FINAL_REPLY)),
        ]);
        $handled = $reviewed = [];

        $answer = app(ClaudeClient::class)->converse(
            'Prepare approved changes only.', 'להחליף איזה כייף בכמה נחמד', $this->tools(),
            function (string $name, array $arguments) use (&$handled): array {
                $this->assertSame(['id' => 8], $arguments);
                if ($name === 'propose_page_update') {
                    $this->assertSame(['read_page'], $handled);
                }
                $handled[] = $name;

                return ['content' => $name === 'read_page' ? 'current live page text' : 'proposal safely prepared'];
            },
            maxTurns: 4,
            reviewReply: function (string $text) use (&$reviewed, &$handled): ?string {
                $reviewed[] = $text;

                return in_array('propose_page_update', $handled, true) ? null : self::CORRECTION;
            },
        );

        $this->assertSame(self::FINAL_REPLY, $answer);
        $this->assertSame(['read_page', 'propose_page_update'], $handled);
        $this->assertSame([self::BAD_REPLY, self::FINAL_REPLY], $reviewed);
        Http::assertSentCount(4);
        $this->assertCorrection(Http::recorded()[1][0], $provider);
        $last = Http::recorded()[3][0];
        $this->assertStringContainsString('current live page text', $last->body());
        $this->assertStringContainsString('proposal safely prepared', $last->body());
        $this->assertStringContainsString('read_page-call', $last->body());
        $this->assertStringContainsString('propose_page_update-call', $last->body());
        if ($provider !== 'openai') {
            $this->assertStringContainsString('read_page-signature', $last->body());
            $this->assertStringContainsString('propose_page_update-signature', $last->body());
        }
    }

    #[DataProvider('providers')]
    public function test_repair_keeps_existing_tool_results_without_reexecuting_the_handler(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::sequence()
            ->push($this->toolResponse($provider, 'read_page'))
            ->push($this->reply($provider, self::BAD_REPLY))
            ->push($this->toolResponse($provider, 'propose_page_update'))
            ->push($this->reply($provider, self::FINAL_REPLY)),
        ]);
        $handled = [];
        $reviews = 0;

        $answer = app(ClaudeClient::class)->converse('s', 'p', $this->tools(), function (string $name) use (&$handled): array {
            $handled[] = $name;

            return ['content' => $name === 'read_page' ? 'read before correction' : 'proposal prepared'];
        }, maxTurns: 4, reviewReply: function () use (&$reviews): ?string {
            return ++$reviews === 1 ? self::CORRECTION : null;
        });

        $this->assertSame(self::FINAL_REPLY, $answer);
        $this->assertSame(['read_page', 'propose_page_update'], $handled);
        $this->assertSame(2, $reviews);
        Http::assertSentCount(4);
        $afterCorrection = Http::recorded()[2][0];
        $this->assertCorrection($afterCorrection, $provider);
        $this->assertStringContainsString('read before correction', $afterCorrection->body());
    }

    #[DataProvider('providers')]
    public function test_a_second_rejected_reply_can_stop_without_a_further_request(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::sequence()
            ->push($this->reply($provider, self::BAD_REPLY))
            ->push($this->reply($provider, self::BAD_REPLY)),
        ]);
        $reviews = 0;

        $this->assertNull(app(ClaudeClient::class)->converse('s', 'p', $this->tools(), function (): array {
            $this->fail('Rejected prose cannot execute a tool.');
        }, maxTurns: 6, reviewReply: function () use (&$reviews): ?string {
            return ++$reviews === 1 ? self::CORRECTION : '';
        }));

        $this->assertSame(2, $reviews);
        Http::assertSentCount(2);
    }

    #[DataProvider('providers')]
    public function test_corrections_cannot_extend_the_total_turn_budget(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::sequence()
            ->push($this->toolResponse($provider, 'read_page'))
            ->push($this->reply($provider, self::BAD_REPLY)),
        ]);
        $handlers = $reviews = 0;

        $this->assertNull(app(ClaudeClient::class)->converse('s', 'p', $this->tools(), function () use (&$handlers): array {
            $handlers++;

            return ['content' => 'read result'];
        }, maxTurns: 2, reviewReply: function () use (&$reviews): string {
            $reviews++;

            return self::CORRECTION;
        }));

        $this->assertSame(1, $handlers);
        $this->assertSame(1, $reviews);
        Http::assertSentCount(2);
    }

    #[DataProvider('providers')]
    public function test_one_opted_in_reserve_repairs_a_final_round_without_repeating_reads(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::sequence()
            ->push($this->toolResponse($provider, 'read_page'))
            ->push($this->reply($provider, self::BAD_REPLY))
            ->push($this->toolResponse($provider, 'propose_page_update')),
        ]);
        $calls = [];
        $this->assertNull(app(ClaudeClient::class)->converse('s', 'p', $this->tools(), function (string $name) use (&$calls): array {
            $calls[] = $name;

            return ['content' => 'validated result'];
        }, maxTurns: 2, reviewReply: fn (): string => self::CORRECTION, replyRepairTurns: 1));
        $this->assertSame(['read_page', 'propose_page_update'], $calls);
        Http::assertSentCount(3);
    }

    #[DataProvider('providers')]
    public function test_repair_reserve_cannot_extend_a_regular_tool_loop(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::response($this->toolResponse($provider, 'read_page'))]);
        $this->assertNull(app(ClaudeClient::class)->converse('s', 'p', $this->tools(), fn (): array => ['content' => 'read'],
            maxTurns: 2, reviewReply: fn (): string => self::CORRECTION, replyRepairTurns: 1));
        Http::assertSentCount(2);
    }

    #[DataProvider('providers')]
    public function test_repeated_bad_prose_cannot_extend_the_single_repair_reserve(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::response($this->reply($provider, self::BAD_REPLY))]);
        $this->assertNull(app(ClaudeClient::class)->converse('s', 'p', $this->tools(), fn (): array => [],
            maxTurns: 2, reviewReply: fn (): string => self::CORRECTION, replyRepairTurns: 99));
        Http::assertSentCount(3);
    }

    #[DataProvider('providers')]
    public function test_an_accepted_answer_is_returned_unchanged(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::response($this->reply($provider, 'איזה טקסט להחליף?'))]);
        $reviews = [];

        $this->assertSame('איזה טקסט להחליף?', app(ClaudeClient::class)->converse('s', 'p', $this->tools(), function (): array {
            $this->fail('A clarification does not call a tool.');
        }, reviewReply: function (string $text) use (&$reviews): ?string {
            $reviews[] = $text;

            return null;
        }));
        $this->assertSame(['איזה טקסט להחליף?'], $reviews);
        Http::assertSentCount(1);
    }

    #[DataProvider('providers')]
    public function test_calls_without_a_reviewer_preserve_the_existing_reply_behavior(string $provider): void
    {
        $this->enable($provider);
        Http::fake(['provider.example/*' => Http::response($this->reply($provider, self::BAD_REPLY))]);

        $this->assertSame(self::BAD_REPLY, app(ClaudeClient::class)->converse('s', 'p', [], fn (): array => ['content' => 'ok']));
        Http::assertSentCount(1);
    }

    public function test_gemini_repairs_reuse_the_same_remote_prefix_without_resending_tools(): void
    {
        $this->enable('google');
        $creations = $generations = $reviews = 0;
        $handled = [];
        Http::fake(['provider.example/*' => function (Request $request) use (&$creations, &$generations) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                $creations++;
                $this->assertCount(2, data_get($request->data(), 'tools.0.functionDeclarations'));
                $this->assertArrayNotHasKey('contents', $request->data());
                $this->assertStringNotContainsString(self::CORRECTION, $request->body());

                return Http::response([
                    'name' => 'cachedContents/repair-prefix',
                    'model' => 'models/gemini-3.1-flash-lite',
                    'expireTime' => now()->addHour()->toIso8601String(),
                ]);
            }

            $generations++;
            $this->assertSame('cachedContents/repair-prefix', $request['cachedContent']);
            $this->assertArrayNotHasKey('tools', $request->data());
            $this->assertArrayNotHasKey('systemInstruction', $request->data());
            $this->assertArrayNotHasKey('toolConfig', $request->data());
            if ($generations === 2) {
                $this->assertCorrection($request, 'google');
            }
            if ($generations === 4) {
                $this->assertSame('read_page-signature', data_get($request->data(), 'contents.3.parts.0.thoughtSignature'));
                $this->assertSame('read_page-call', data_get($request->data(), 'contents.4.parts.0.functionResponse.id'));
                $this->assertSame('propose_page_update-signature', data_get($request->data(), 'contents.5.parts.0.thoughtSignature'));
                $this->assertSame('propose_page_update-call', data_get($request->data(), 'contents.6.parts.0.functionResponse.id'));
            }

            return Http::response(match ($generations) {
                1 => $this->reply('google', self::BAD_REPLY),
                2 => $this->toolResponse('google', 'read_page'),
                3 => $this->toolResponse('google', 'propose_page_update'),
                default => $this->reply('google', self::FINAL_REPLY),
            });
        }]);

        $answer = app(ClaudeClient::class)->converse('stable rules', 'private owner request', $this->tools(), function (string $name) use (&$handled): array {
            $handled[] = $name;

            return ['content' => $name.' result'];
        }, maxTurns: 4, cacheScope: 'site-agent:customer:1:site:2', reviewReply: function () use (&$reviews): ?string {
            return ++$reviews === 1 ? self::CORRECTION : null;
        });

        $this->assertSame(self::FINAL_REPLY, $answer);
        $this->assertSame(['read_page', 'propose_page_update'], $handled);
        $this->assertSame(1, $creations);
        $this->assertSame(4, $generations);
        $this->assertSame(2, $reviews);
        Http::assertSentCount(5);
    }
}
