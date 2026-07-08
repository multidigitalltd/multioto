<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the ticket-AI layer. Supports two providers behind one
 * `structured()` method: Anthropic (Claude) and any OpenAI-compatible endpoint
 * (OpenAI, Azure OpenAI, OpenRouter, or a local model server).
 *
 * Kept deliberately minimal — all ticket-AI logic lives in the jobs. Returns
 * null when the layer is disabled, unconfigured, or on any failure, so callers
 * degrade to manual handling instead of breaking.
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

        try {
            return config('billing.ai.provider') === 'openai'
                ? $this->openAi($system, $prompt, $schema)
                : $this->anthropic($system, $prompt, $schema);
        } catch (\Throwable) {
            // The AI layer is best-effort — never let it break ticket handling.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function anthropic(string $system, string $prompt, array $schema): ?array
    {
        $config = config('billing.ai');

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

        // A safety refusal returns 200 with stop_reason=refusal and no content.
        if ($response->failed() || $response->json('stop_reason') === 'refusal') {
            return null;
        }

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'text' && filled($block['text'] ?? null)) {
                return $this->decode($block['text']);
            }
        }

        return null;
    }

    /**
     * OpenAI-compatible chat/completions with a json_schema response format.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function openAi(string $system, string $prompt, array $schema): ?array
    {
        $config = config('billing.ai');

        $response = Http::baseUrl(rtrim((string) $config['base_url'], '/'))
            ->withToken($config['api_key'])
            ->timeout(60)
            ->post('/chat/completions', [
                'model' => $config['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'response', 'strict' => true, 'schema' => $schema],
                ],
            ]);

        if ($response->failed()) {
            return null;
        }

        // A refusal comes back as a `refusal` field on the message.
        if (filled($response->json('choices.0.message.refusal'))) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        return filled($content) ? $this->decode($content) : null;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
