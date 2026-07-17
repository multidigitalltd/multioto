<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\SystemLog;
use App\Services\Health\ConnectionResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin client for the ticket-AI layer. Supports three providers behind one
 * `structured()` method: Anthropic (Claude), any OpenAI-compatible endpoint
 * (OpenAI, Azure OpenAI, OpenRouter, or a local model server), and Google
 * Gemini's native API.
 *
 * Kept deliberately minimal — all ticket-AI logic lives in the jobs. Returns
 * null when the layer is disabled, unconfigured, or on any failure, so callers
 * degrade to manual handling instead of breaking.
 */
class ClaudeClient
{
    /** The most recent provider error (HTTP status + message), for the UI. */
    private ?string $lastError = null;

    public function isEnabled(): bool
    {
        return (bool) config('billing.ai.enabled') && filled(config('billing.ai.api_key'));
    }

    /** The reason the last AI call failed (HTTP status + provider detail), if any. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Whether the AGENTIC layer (tool use → converse()) can run. Tool use is
     * implemented for all three providers (Anthropic, OpenAI-compatible, Google
     * Gemini), so this is simply "the AI layer is on and configured".
     */
    public function supportsAgent(): bool
    {
        return $this->isEnabled();
    }

    /**
     * Verify the AI provider is reachable and the key/model work, by asking for
     * a trivial JSON object. Surfaces a clear reason so "the agent isn't drafting"
     * stops being a silent mystery. The detailed HTTP error is written to the log.
     */
    public function testConnection(): ConnectionResult
    {
        if (! (bool) config('billing.ai.enabled')) {
            return ConnectionResult::notConfigured('סוכן ה-AI כבוי — הפעילו אותו ושמרו, ואז בדקו חיבור.');
        }

        if (blank(config('billing.ai.api_key'))) {
            return ConnectionResult::notConfigured('לא הוגדר מפתח API לסוכן.');
        }

        $this->lastError = null;

        $result = $this->structured(
            system: 'ענה במבנה JSON בלבד.',
            prompt: 'החזר {"ok": true}.',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['ok' => ['type' => 'boolean']],
                'required' => ['ok'],
            ],
        );

        $where = config('billing.ai.provider').' · '.config('billing.ai.model');

        if ($result === null) {
            // Surface the real provider error on screen (also saved in מערכת ועדכונים).
            $reason = $this->lastError
                ? Str::limit($this->lastError, 300)
                : 'הדגם החזיר תשובה ריקה או סירב.';

            return ConnectionResult::fail("הבקשה לספק ה-AI נכשלה ({$where}):\n{$reason}");
        }

        // The console and the site agent don't use plain completion — they use
        // the TOOL-USE layer (converse). A provider/model can pass the simple
        // check above yet reject tool calls, which is exactly what leaves the
        // console silent ("לא התקבלה תשובה"). Probe tool-use too, so a green
        // result actually means the agent will work.
        $this->lastError = null;
        $toolProbe = $this->converse(
            system: 'ענה בקצרה.',
            prompt: 'כתוב: בסדר',
            tools: [[
                'name' => 'noop',
                'description' => 'אין להשתמש בכלי זה — כלי בדיקה בלבד.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ]],
            handler: fn (): array => ['content' => 'ok'],
            maxTurns: 1,
        );

        if ($toolProbe === null) {
            $reason = $this->lastError
                ? Str::limit($this->lastError, 300)
                : 'שכבת ה-tool-use החזירה תשובה ריקה או סירבה.';

            return ConnectionResult::fail(
                "הקריאה הבסיסית תקינה, אך שכבת ה-tool-use — שהמסוף והסוכן צריכים — נכשלה ({$where}):\n{$reason}\n"
                    .'לרוב זה מודל שאינו תומך בקריאות פונקציה; נסו מודל אחר.'
            );
        }

        return ConnectionResult::ok("החיבור לספק ה-AI תקין ✓ כולל tool-use ({$where})");
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
            return match (config('billing.ai.provider')) {
                'openai' => $this->openAi($system, $prompt, $schema),
                'google' => $this->google($system, $prompt, $schema),
                default => $this->anthropic($system, $prompt, $schema),
            };
        } catch (\Throwable $e) {
            // The AI layer is best-effort — never let it break ticket handling.
            // Record so a misconfiguration is diagnosable instead of silent.
            $provider = (string) config('billing.ai.provider');
            $this->lastError = $e->getMessage();
            Log::warning('AI request threw', ['provider' => $provider, 'error' => $e->getMessage()]);
            SystemLog::record('error', 'ai', "בקשת AI נכשלה ({$provider}): ".Str::limit($e->getMessage(), 200), [
                'provider' => $provider,
                'model' => config('billing.ai.model'),
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            return null;
        }
    }

    /**
     * Run a tool-use conversation with Claude (Anthropic only). The model may
     * call the supplied tools; $handler executes each call and returns its
     * result. Loops until the model stops requesting tools or a turn cap is
     * hit, and returns the model's final text — or null on failure/refusal.
     *
     * Tool inputs reach $handler already decoded. The handler returns
     * ['content' => string, 'is_error' => bool]. Every mutating side-effect is
     * the handler's responsibility — this method only relays the conversation.
     *
     * @param  list<array<string, mixed>>  $tools  Anthropic tool definitions.
     * @param  callable(string, array<string, mixed>): array{content: string, is_error?: bool}  $handler
     */
    public function converse(string $system, string $prompt, array $tools, callable $handler, int $maxTurns = 6): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            return match (config('billing.ai.provider', 'anthropic')) {
                'openai' => $this->converseOpenai($system, $prompt, $tools, $handler, $maxTurns),
                'google' => $this->converseGoogle($system, $prompt, $tools, $handler, $maxTurns),
                default => $this->converseAnthropic($system, $prompt, $tools, $handler, $maxTurns),
            };
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('AI converse threw', ['provider' => config('billing.ai.provider'), 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Anthropic Messages API tool-use loop.
     *
     * @param  list<array<string, mixed>>  $tools
     * @param  callable(string, array<string, mixed>): array{content: string, is_error?: bool}  $handler
     */
    private function converseAnthropic(string $system, string $prompt, array $tools, callable $handler, int $maxTurns): ?string
    {
        $config = config('billing.ai');
        $messages = [['role' => 'user', 'content' => $prompt]];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders(['x-api-key' => $config['api_key'], 'anthropic-version' => '2023-06-01'])
                ->timeout(90)
                ->post('/v1/messages', [
                    'model' => $config['model'],
                    'max_tokens' => 2048,
                    'system' => $system,
                    'thinking' => ['type' => 'adaptive'],
                    'output_config' => ['effort' => $config['effort']],
                    'tools' => $tools,
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                $this->logFailure('anthropic', $response->status(), $response->body());

                return null;
            }

            $this->recordUsage($response);

            if ($response->json('stop_reason') === 'refusal') {
                return null;
            }

            $content = (array) $response->json('content', []);
            // Echo the assistant turn back unchanged (thinking blocks included).
            $messages[] = ['role' => 'assistant', 'content' => $content];

            $toolUses = array_values(array_filter($content, fn ($b): bool => ($b['type'] ?? null) === 'tool_use'));

            if ($toolUses === []) {
                return $this->joinText($content);
            }

            $results = [];
            foreach ($toolUses as $call) {
                $out = $handler((string) ($call['name'] ?? ''), (array) ($call['input'] ?? []));
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $call['id'] ?? '',
                    'content' => (string) ($out['content'] ?? ''),
                    'is_error' => (bool) ($out['is_error'] ?? false),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return null;
    }

    /**
     * OpenAI-compatible chat/completions tool-use loop (function calling).
     *
     * @param  list<array<string, mixed>>  $tools  Anthropic-style tool defs (name/description/input_schema).
     * @param  callable(string, array<string, mixed>): array{content: string, is_error?: bool}  $handler
     */
    private function converseOpenai(string $system, string $prompt, array $tools, callable $handler, int $maxTurns): ?string
    {
        $config = config('billing.ai');
        $functions = array_map(fn (array $t): array => [
            'type' => 'function',
            'function' => [
                'name' => (string) ($t['name'] ?? ''),
                'description' => (string) ($t['description'] ?? ''),
                'parameters' => $t['input_schema'] ?? ['type' => 'object', 'properties' => (object) []],
            ],
        ], $tools);

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = Http::baseUrl(rtrim((string) $config['base_url'], '/'))
                ->withToken($config['api_key'])
                ->timeout(90)
                ->post('/chat/completions', [
                    'model' => $config['model'],
                    'messages' => $messages,
                    'tools' => $functions,
                ]);

            if ($response->failed()) {
                $this->logFailure('openai', $response->status(), $response->body());

                return null;
            }

            $this->recordUsage($response);

            $message = (array) $response->json('choices.0.message', []);
            $toolCalls = array_values((array) ($message['tool_calls'] ?? []));

            // Echo the assistant turn back — with its tool_calls when present.
            $assistant = ['role' => 'assistant', 'content' => $message['content'] ?? ''];
            if ($toolCalls !== []) {
                $assistant['tool_calls'] = $toolCalls;
            }
            $messages[] = $assistant;

            if ($toolCalls === []) {
                $text = (string) ($message['content'] ?? '');

                return $text !== '' ? $text : null;
            }

            foreach ($toolCalls as $call) {
                $args = json_decode((string) data_get($call, 'function.arguments', '{}'), true);
                $out = $handler((string) data_get($call, 'function.name', ''), is_array($args) ? $args : []);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'content' => (string) ($out['content'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * Google Gemini (native API) tool-use loop (function calling).
     *
     * @param  list<array<string, mixed>>  $tools  Anthropic-style tool defs (name/description/input_schema).
     * @param  callable(string, array<string, mixed>): array{content: string, is_error?: bool}  $handler
     */
    private function converseGoogle(string $system, string $prompt, array $tools, callable $handler, int $maxTurns): ?string
    {
        $config = config('billing.ai');
        $model = rawurlencode(preg_replace('#^models/#', '', trim((string) $config['model'])));
        $declarations = array_map(fn (array $t): array => array_filter([
            'name' => (string) ($t['name'] ?? ''),
            'description' => (string) ($t['description'] ?? ''),
            'parameters' => isset($t['input_schema']) ? $this->toGeminiSchema((array) $t['input_schema']) : null,
        ], fn ($v): bool => $v !== null), $tools);

        $contents = [['role' => 'user', 'parts' => [['text' => $prompt]]]];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = Http::baseUrl($this->googleBase($config['base_url'] ?? null))
                ->withHeaders(['x-goog-api-key' => $config['api_key']])
                ->timeout(90)
                ->post("/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => $contents,
                    'tools' => [['functionDeclarations' => array_values($declarations)]],
                ]);

            if ($response->failed()) {
                $this->logFailure('google', $response->status(), $response->body());

                return null;
            }

            $this->recordUsage($response);

            $parts = (array) $response->json('candidates.0.content.parts', []);
            $contents[] = ['role' => 'model', 'parts' => $parts];

            $calls = array_values(array_filter($parts, fn ($p): bool => isset($p['functionCall'])));

            if ($calls === []) {
                $text = collect($parts)->pluck('text')->filter()->implode("\n");

                return $text !== '' ? $text : null;
            }

            $responseParts = [];
            foreach ($calls as $part) {
                $fc = (array) $part['functionCall'];
                $out = $handler((string) ($fc['name'] ?? ''), (array) ($fc['args'] ?? []));
                // Echo back the call id when Gemini supplies one (parallel calls,
                // incl. several to the same function) so each response correlates
                // to its originating call; omit it otherwise.
                $responseParts[] = ['functionResponse' => array_filter([
                    'id' => $fc['id'] ?? null,
                    'name' => (string) ($fc['name'] ?? ''),
                    'response' => ['result' => (string) ($out['content'] ?? '')],
                ], fn ($v): bool => $v !== null)];
            }

            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        return null;
    }

    /** Concatenate the text blocks of an Anthropic content array. */
    private function joinText(array $content): ?string
    {
        $text = collect($content)
            ->filter(fn ($b): bool => is_array($b) && ($b['type'] ?? null) === 'text')
            ->pluck('text')
            ->implode("\n");

        return $text !== '' ? $text : null;
    }

    /**
     * Record an HTTP-level provider failure — to the log AND the in-panel system
     * log — and keep a concise reason for the UI (connection test / diagnostics).
     */
    private function logFailure(string $provider, int $status, string $body): void
    {
        $detail = $this->extractError($body);
        $this->lastError = "HTTP {$status} — {$detail}";

        Log::warning('AI request failed', ['provider' => $provider, 'status' => $status, 'body' => Str::limit($body, 300)]);
        SystemLog::record('error', 'ai', "בקשת AI נכשלה ({$provider}): HTTP {$status}", [
            'provider' => $provider,
            'model' => config('billing.ai.model'),
            'status' => $status,
            'detail' => Str::limit($detail, 500),
        ]);
    }

    /**
     * Record the token usage of one successful provider response for the cost
     * dashboard. Each provider reports usage under a different key; a missing
     * count is simply recorded as zero (the request itself is still counted).
     */
    private function recordUsage(Response $response): void
    {
        [$input, $output] = match (config('billing.ai.provider')) {
            'openai' => [
                (int) $response->json('usage.prompt_tokens', 0),
                (int) $response->json('usage.completion_tokens', 0),
            ],
            'google' => [
                (int) $response->json('usageMetadata.promptTokenCount', 0),
                // Everything billed beyond the prompt (candidates + any thinking).
                max(0, (int) $response->json('usageMetadata.totalTokenCount', 0) - (int) $response->json('usageMetadata.promptTokenCount', 0)),
            ],
            default => [
                (int) $response->json('usage.input_tokens', 0),
                (int) $response->json('usage.output_tokens', 0),
            ],
        };

        AiUsage::record(
            (string) config('billing.ai.provider'),
            (string) config('billing.ai.model'),
            $input,
            $output,
        );
    }

    /** Pull the human-readable error message out of a provider error body. */
    private function extractError(string $body): string
    {
        $json = json_decode($body, true);
        $message = data_get($json, 'error.message')
            ?? data_get($json, 'error')
            ?? data_get($json, 'message');

        return is_string($message) && $message !== '' ? $message : Str::limit($body, 200);
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
            if ($response->failed()) {
                $this->logFailure('anthropic', $response->status(), $response->body());
            }

            return null;
        }

        $this->recordUsage($response);

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
            $this->logFailure('openai', $response->status(), $response->body());

            return null;
        }

        $this->recordUsage($response);

        // A refusal comes back as a `refusal` field on the message.
        if (filled($response->json('choices.0.message.refusal'))) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        return filled($content) ? $this->decode($content) : null;
    }

    /**
     * Google Gemini (native API): generateContent with a JSON response schema.
     *
     * Auth is the X-goog-api-key header; structured output is requested via
     * generationConfig.responseSchema (an OpenAPI subset), so the model returns
     * a JSON object matching $schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function google(string $system, string $prompt, array $schema): ?array
    {
        $config = config('billing.ai');
        // Gemini's endpoint is /v1beta/models/{model}:generateContent, so {model}
        // must be the bare id ("gemini-flash-latest"). Be forgiving if a value was
        // pasted with Google's "models/" resource prefix — otherwise Gemini rejects
        // it with "unexpected model name format".
        $model = rawurlencode(preg_replace('#^models/#', '', trim((string) $config['model'])));

        $response = Http::baseUrl($this->googleBase($config['base_url'] ?? null))
            ->withHeaders(['x-goog-api-key' => $config['api_key']])
            ->timeout(60)
            ->post("/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->toGeminiSchema($schema),
                ],
            ]);

        // A safety block returns 200 with no candidates (or promptFeedback only).
        if ($response->failed()) {
            $this->logFailure('google', $response->status(), $response->body());

            return null;
        }

        $this->recordUsage($response);

        $text = $response->json('candidates.0.content.parts.0.text');

        return filled($text) ? $this->decode($text) : null;
    }

    /**
     * The Gemini host root. Defaults to Google's endpoint and is forgiving if the
     * OpenAI-compatible path was pasted into the base-URL setting by mistake.
     */
    private function googleBase(?string $base): string
    {
        $base = rtrim((string) ($base ?: 'https://generativelanguage.googleapis.com'), '/');
        $base = preg_replace('#/v1beta/openai$#', '', $base);

        return $base !== '' ? $base : 'https://generativelanguage.googleapis.com';
    }

    /**
     * Translate our JSON Schema to Gemini's responseSchema dialect: drop
     * `additionalProperties` (unsupported), upper-case the `type` enum, and
     * recurse into properties/items. Everything else (enum, required,
     * description) carries over unchanged.
     *
     * A nullable union `type` — e.g. ["string","null"], the cross-provider way
     * OpenAI/Anthropic mark an optional field — is NOT valid in Gemini's dialect
     * (it wants a single scalar type). Collapse it to the primary type plus
     * `nullable: true`, otherwise Gemini rejects the whole request with HTTP 400
     * and the call returns null.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $value) {
            if ($key === 'additionalProperties') {
                continue;
            }

            if ($key === 'type' && is_array($value)) {
                // Union type (nullable): keep the first non-null type, and mark
                // the field nullable if "null" was one of the options.
                $named = array_values(array_filter($value, fn ($t): bool => strtolower((string) $t) !== 'null'));
                $out['type'] = strtoupper((string) ($named[0] ?? 'string'));
                if (count($named) !== count($value)) {
                    $out['nullable'] = true;
                }

                continue;
            }

            $out[$key] = match (true) {
                $key === 'type' && is_string($value) => strtoupper($value),
                $key === 'properties' && is_array($value) => array_map(fn ($v) => $this->toGeminiSchema($v), $value),
                $key === 'items' && is_array($value) => $this->toGeminiSchema($value),
                default => $value,
            };
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
