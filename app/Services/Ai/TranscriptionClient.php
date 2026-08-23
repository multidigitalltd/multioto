<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Speech to text, against a model running on our own server.
 *
 * A thin client on purpose (§5): it turns audio into a string and decides
 * nothing. What the words mean, and whether anything happens because of them,
 * is the agent's job — and every action it proposes still waits for the same
 * approval a typed instruction would.
 *
 * The response shape is read tolerantly. Local speech servers all speak roughly
 * the OpenAI dialect and disagree in the details — `text`, `transcription`, a
 * list of `segments` — and a client that understood only one of them would
 * return an empty string from a request that actually worked. When nothing
 * matches, the keys that DID arrive are logged, so an unfamiliar server is a
 * five-minute fix instead of a mystery.
 */
class TranscriptionClient
{
    /** Configured and switched on. */
    public function enabled(): bool
    {
        return (bool) config('transcription.enabled') && filled(config('transcription.url'));
    }

    /** The largest upload this installation accepts. */
    public function maxBytes(): int
    {
        return max(1, (int) config('transcription.max_bytes', 26214400));
    }

    /**
     * The words in a recording, or null when we could not get them.
     *
     * Null is never "nothing was said" — it is "we do not know", and the caller
     * has to keep those apart. Reporting silence for a failed request would put
     * an empty instruction in front of the agent as though the person had
     * nothing to say.
     *
     * @param  string  $path  a readable local file
     */
    public function transcribe(string $path, ?string $filename = null): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! is_readable($path)) {
            Log::warning('TranscriptionClient: the recording could not be read', ['path' => $path]);

            return null;
        }

        $headers = filled($key = (string) config('transcription.api_key'))
            ? ['Authorization' => 'Bearer '.$key]
            : [];

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('transcription.timeout_seconds', 120))
                ->attach('file', file_get_contents($path), $filename ?: basename($path))
                ->post((string) config('transcription.url'), array_filter([
                    'model' => (string) config('transcription.model'),
                    'language' => (string) config('transcription.language') ?: null,
                    'response_format' => 'json',
                ]));
        } catch (\Throwable $e) {
            Log::warning('TranscriptionClient: request failed', ['error' => Str::limit($e->getMessage(), 300)]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('TranscriptionClient: HTTP error', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);

            return null;
        }

        return $this->textFrom($response->json(), $response->body());
    }

    /**
     * Pull the transcript out of whatever shape came back.
     *
     * @param  mixed  $json
     */
    private function textFrom($json, string $raw): ?string
    {
        // Some servers answer text/plain with the transcript itself.
        if (! is_array($json)) {
            return $this->clean($raw);
        }

        foreach (['text', 'transcription', 'transcript', 'result'] as $key) {
            if (is_string($json[$key] ?? null) && trim($json[$key]) !== '') {
                return $this->clean($json[$key]);
            }
        }

        // Segment lists, which whisper.cpp and faster-whisper both produce.
        if (is_array($segments = $json['segments'] ?? null)) {
            $joined = trim(implode(' ', array_map(
                fn ($segment): string => trim((string) ($segment['text'] ?? '')),
                $segments,
            )));

            if ($joined !== '') {
                return $this->clean($joined);
            }
        }

        // Nothing recognised. The KEYS are logged, never the values — a
        // transcript is somebody talking about their customers, and it does not
        // belong in a log line.
        Log::warning('TranscriptionClient: unfamiliar response shape', [
            'keys' => array_keys($json),
        ]);

        return null;
    }

    private function clean(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text !== '' ? $text : null;
    }
}
