<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Claude API (Messages endpoint).
 *
 * Kept deliberately minimal — all ticket-AI logic lives in the jobs. Returns
 * null when the layer is disabled or unconfigured so callers degrade to manual
 * handling instead of failing.
 */
class ClaudeClient
{
    public function isEnabled(): bool
    {
        return (bool) config('billing.ai.enabled') && filled(config('billing.ai.api_key'));
    }

    /**
     * Ask the model for a JSON object matching $schema (structured outputs).
     * Returns the decoded object, or null on any failure.
     *
     * @param  array<string, mixed>  $schema  JSON Schema for the response object.
     * @return array<string, mixed>|null
     */
    public function structured(string $system, string $prompt, array $schema): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $config = config('billing.ai');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders([
                    'x-api-key' => $config['api_key'],
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout(60)
                ->post('/v1/messages', [
                    'model' => $config['model'],
                    'max_tokens' => 1024,
                    'system' => $system,
                    'thinking' => ['type' => 'adaptive'],
                    'output_config' => [
                        'effort' => $config['effort'],
                        'format' => ['type' => 'json_schema', 'schema' => $schema],
                    ],
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->failed()) {
                return null;
            }

            // A safety refusal returns 200 with stop_reason=refusal and no usable content.
            if (($response->json('stop_reason') === 'refusal')) {
                return null;
            }

            foreach ($response->json('content', []) as $block) {
                if (($block['type'] ?? null) === 'text' && filled($block['text'] ?? null)) {
                    return json_decode($block['text'], true) ?: null;
                }
            }

            return null;
        } catch (\Throwable) {
            // The AI layer is best-effort — never let it break ticket handling.
            return null;
        }
    }
}
