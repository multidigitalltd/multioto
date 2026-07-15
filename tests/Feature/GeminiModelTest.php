<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiModelTest extends TestCase
{
    use RefreshDatabase;

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
