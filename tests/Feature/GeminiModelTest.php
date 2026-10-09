<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentAcfActions;
use App\Services\SiteAgent\SiteAgentToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiModelTest extends TestCase
{
    use RefreshDatabase;

    private function enableGemini(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-flash-latest',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.assistant.disabled_permissions' => '',
        ]);
        Http::preventStrayRequests();
    }

    public function test_the_site_catalog_uses_native_json_schema_without_losing_dynamic_fields(): void
    {
        $this->enableGemini();
        $site = new Site;
        $site->mcp_capabilities = [
            'server' => ['version' => '1.11.0'],
            'tools' => array_map(fn (string $name): array => ['name' => $name], array_unique([
                ...SiteAgentToolbox::pluginTools(),
                ...app(SiteActionProposer::class)->pluginTools(),
            ])),
        ];
        $tools = [
            ...app(SiteAgentToolbox::class)->definitions($site),
            ...app(SiteActionProposer::class)->definitions($site),
        ];
        $this->assertGreaterThan(65, count($tools));
        $this->assertContains(SiteAgentAcfActions::TOOL, array_column($tools, 'name'));

        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use ($tools) {
            $declarations = data_get(json_decode($request->body(), true), 'tools.0.functionDeclarations');
            $this->assertCount(count($tools), $declarations);

            foreach ($tools as $index => $tool) {
                $declaration = $declarations[$index];
                $this->assertSame($tool['name'], $declaration['name']);
                $this->assertArrayNotHasKey('parameters', $declaration);
                $this->assertArrayHasKey('parametersJsonSchema', $declaration);
                $this->assertSame(
                    json_decode(json_encode($tool['input_schema']), true),
                    $declaration['parametersJsonSchema'],
                    'A schema conversion must not narrow '.$tool['name'],
                );
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'איזה טקסט תרצה להחליף בדף הבית?']]]]]]);
        }]);

        $this->assertSame('איזה טקסט תרצה להחליף בדף הבית?', app(ClaudeClient::class)->converse(
            'ענה בעברית.', 'להחליף טקסט בדף הבית', $tools,
            function (): array {
                $this->fail('A clarification should not perform a tool call.');
            },
        ));
        Http::assertSentCount(1);
    }

    public function test_acf_tool_calls_preserve_mixed_paths_and_native_json_values(): void
    {
        $this->enableGemini();
        $tools = app(SiteAgentAcfActions::class)->definitions(new Site);
        $arguments = [
            'context' => 'post', 'id' => 7, 'field_key' => 'field_rows',
            'operations' => [
                ['op' => 'set', 'path' => [0, 'field_title'], 'value' => 'כותרת חדשה'],
                ['op' => 'set', 'path' => [0, 'field_flag'], 'value' => false],
                ['op' => 'set', 'path' => [0, 'field_count'], 'value' => 12],
                ['op' => 'set', 'path' => [0, 'field_link'], 'value' => null],
                ['op' => 'set', 'path' => [0, 'field_gallery'], 'value' => [19, 21]],
                ['op' => 'insert', 'path' => [], 'index' => 1, 'value' => ['field_title' => 'עוד שורה', 'field_flag' => true]],
            ],
        ];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [[
                'functionCall' => ['id' => 'acf-call', 'name' => SiteAgentAcfActions::TOOL, 'args' => $arguments],
                'thoughtSignature' => 'opaque-signature',
            ]]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'ההצעה מוכנה לאישור.']]]]]]),
        ]);
        $calls = 0;

        $answer = app(ClaudeClient::class)->converse('s', 'p', $tools, function (string $name, array $input) use ($arguments, &$calls): array {
            $calls++;
            $this->assertSame(SiteAgentAcfActions::TOOL, $name);
            $this->assertSame($arguments, $input);

            return ['content' => 'prepared for approval'];
        });

        $this->assertSame('ההצעה מוכנה לאישור.', $answer);
        $this->assertSame(1, $calls);
        Http::assertSentCount(2);
        $second = json_decode(Http::recorded()[1][0]->body(), true);
        $this->assertSame($arguments, data_get($second, 'contents.1.parts.0.functionCall.args'));
        $this->assertSame('opaque-signature', data_get($second, 'contents.1.parts.0.thoughtSignature'));
        $this->assertSame('acf-call', data_get($second, 'contents.2.parts.0.functionResponse.id'));
    }

    public function test_a_reused_client_reports_only_the_current_calls_error(): void
    {
        $this->enableGemini();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'temporary provider error']], 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'בסדר']]]]]])
            ->push(['error' => ['message' => 'another temporary error']], 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]),
        ]);
        $client = app(ClaudeClient::class);
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
        $handler = fn (): array => ['content' => 'ok'];

        $this->assertNull($client->structured('s', 'p', $schema));
        $this->assertStringContainsString('503', (string) $client->lastError());
        $this->assertSame('בסדר', $client->converse('s', 'p', [], $handler));
        $this->assertNull($client->lastError());
        $this->assertNull($client->converse('s', 'p', [], $handler));
        $this->assertStringContainsString('503', (string) $client->lastError());
        $this->assertSame(['ok' => true], $client->structured('s', 'p', $schema));
        $this->assertNull($client->lastError());
    }

    public function test_it_strips_the_models_prefix_from_a_gemini_model_name(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'k',
            'billing.ai.provider' => 'google',
            // Pasted with Google's "models/" resource prefix — must still work.
            'billing.ai.model' => 'models/gemini-flash-latest',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            ]),
        ]);

        $result = app(ClaudeClient::class)->structured('s', 'p', [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
        ]);

        $this->assertSame(['ok' => true], $result);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, '/v1beta/models/gemini-flash-latest:generateContent')
                && ! str_contains($url, 'models%2F')       // no encoded slash
                && ! str_contains($url, 'models/models');   // no doubled prefix
        });
    }

    public function test_it_collapses_nullable_union_types_for_geminis_schema(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'k',
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-flash-latest',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"intent":"unknown","detail":"x"}']]]]],
            ]),
        ]);

        // A schema with nullable unions (how OpenAI/Anthropic mark optional
        // fields) — Gemini rejects array-typed `type`, so this must be collapsed.
        app(ClaudeClient::class)->structured('s', 'p', [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => ['ticket_reply', 'unknown']],
                'ticket_id' => ['type' => ['integer', 'null']],
                'customer_name' => ['type' => ['string', 'null']],
                'detail' => ['type' => 'string'],
            ],
            'required' => ['intent', 'detail'],
        ]);

        Http::assertSent(function ($request) {
            $schema = data_get($request->data(), 'generationConfig.responseSchema');

            // No array-typed `type` survives anywhere (that is what Gemini rejects).
            $flat = json_encode($schema, JSON_UNESCAPED_UNICODE);
            $noArrayTypes = ! preg_match('/"type"\s*:\s*\[/', (string) $flat);

            $ticketId = data_get($schema, 'properties.ticket_id');
            $name = data_get($schema, 'properties.customer_name');

            return $noArrayTypes
                && $ticketId['type'] === 'INTEGER' && ($ticketId['nullable'] ?? false) === true
                && $name['type'] === 'STRING' && ($name['nullable'] ?? false) === true
                && data_get($schema, 'properties.detail.type') === 'STRING'
                && ! array_key_exists('nullable', (array) data_get($schema, 'properties.detail'));
        });
    }
}
