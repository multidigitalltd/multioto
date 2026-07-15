<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A day's token usage for one AI model. Rows accumulate as the agent runs; the
 * cost is derived at read time from the model's price (config billing.ai.pricing),
 * so re-pricing never requires a backfill.
 *
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $requests
 */
class AiUsage extends Model
{
    protected $table = 'ai_usage_daily';

    protected $fillable = ['date', 'provider', 'model', 'input_tokens', 'output_tokens', 'requests'];

    // NB: `date` is kept as a plain 'Y-m-d' string (no date cast). A cast would
    // serialize to 'Y-m-d H:i:s', so firstOrCreate() on a 'Y-m-d' key would miss
    // the existing daily row and hit the unique constraint — silently dropping
    // usage after the first call of the day.

    /**
     * Add one call's token counts to today's row for this model. Best-effort:
     * usage accounting must never break an AI call, so failures are swallowed.
     */
    public static function record(string $provider, string $model, int $inputTokens, int $outputTokens): void
    {
        try {
            $row = static::query()->firstOrCreate(
                ['date' => now()->toDateString(), 'provider' => $provider, 'model' => $model ?: 'unknown'],
            );

            $row->increment('requests');
            if ($inputTokens > 0) {
                $row->increment('input_tokens', $inputTokens);
            }
            if ($outputTokens > 0) {
                $row->increment('output_tokens', $outputTokens);
            }
        } catch (\Throwable) {
            // Deliberately ignored — accounting is not worth failing a request for.
        }
    }

    /** USD price [input, output] per 1M tokens for a model name (substring match). */
    public static function priceFor(string $model): array
    {
        $model = strtolower(trim($model));
        $prices = (array) config('billing.ai.pricing', []);

        // Most-specific (longest) key that appears in the model name wins, so
        // "gemini-2.5-flash-lite" prices as lite, not as flash.
        $keys = array_filter(array_keys($prices), fn ($k): bool => $k !== '*');
        usort($keys, fn ($a, $b): int => strlen((string) $b) <=> strlen((string) $a));

        foreach ($keys as $key) {
            if ($model !== '' && str_contains($model, strtolower((string) $key))) {
                return [(float) ($prices[$key][0] ?? 0), (float) ($prices[$key][1] ?? 0)];
            }
        }

        $fallback = $prices['*'] ?? [0, 0];

        return [(float) ($fallback[0] ?? 0), (float) ($fallback[1] ?? 0)];
    }

    /** USD cost of this row, from its model's price. */
    public function costUsd(): float
    {
        [$in, $out] = self::priceFor((string) $this->model);

        return ($this->input_tokens / 1_000_000) * $in + ($this->output_tokens / 1_000_000) * $out;
    }

    /**
     * Aggregate cost + tokens across all rows since $since (null = all time).
     *
     * @return array{usd: float, input_tokens: int, output_tokens: int, requests: int}
     */
    public static function totals(?\DateTimeInterface $since = null): array
    {
        $rows = static::query()
            ->when($since, fn ($q) => $q->where('date', '>=', $since->format('Y-m-d')))
            ->get(['model', 'input_tokens', 'output_tokens', 'requests']);

        return [
            'usd' => round($rows->sum(fn (self $r): float => $r->costUsd()), 4),
            'input_tokens' => (int) $rows->sum('input_tokens'),
            'output_tokens' => (int) $rows->sum('output_tokens'),
            'requests' => (int) $rows->sum('requests'),
        ];
    }

    /**
     * Per-model cost breakdown since $since, most expensive first.
     *
     * @return list<array{model: string, usd: float, input_tokens: int, output_tokens: int, requests: int}>
     */
    public static function byModel(?\DateTimeInterface $since = null): array
    {
        return static::query()
            ->when($since, fn ($q) => $q->where('date', '>=', $since->format('Y-m-d')))
            ->select('model')
            ->selectRaw('SUM(input_tokens) as input_tokens')
            ->selectRaw('SUM(output_tokens) as output_tokens')
            ->selectRaw('SUM(requests) as requests')
            ->groupBy('model')
            ->get()
            ->map(function ($r): array {
                $usage = new self(['model' => $r->model, 'input_tokens' => (int) $r->input_tokens, 'output_tokens' => (int) $r->output_tokens]);

                return [
                    'model' => (string) $r->model,
                    'usd' => round($usage->costUsd(), 4),
                    'input_tokens' => (int) $r->input_tokens,
                    'output_tokens' => (int) $r->output_tokens,
                    'requests' => (int) $r->requests,
                ];
            })
            ->sortByDesc('usd')
            ->values()
            ->all();
    }
}
