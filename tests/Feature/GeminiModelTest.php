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
}
