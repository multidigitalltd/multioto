<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use Illuminate\Support\Facades\Cache;

/**
 * Estimated AI spend for the agent dashboard. The figure is cached for 24 hours
 * — the team asked for a once-a-day number, and it keeps the page instant — with
 * a manual refresh that busts the cache. Amounts are USD estimates from the
 * configured price table; the provider's own invoice is the source of truth.
 */
class AiCostReporter
{
    public const CACHE_KEY = 'ai.cost_snapshot';

    /**
     * @return array{total: array{usd: float, input_tokens: int, output_tokens: int, requests: int}, this_month: array{usd: float, input_tokens: int, output_tokens: int, requests: int}, by_model: list<array<string, mixed>>, as_of: string}
     */
    public function snapshot(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addDay(), fn (): array => [
            'total' => AiUsage::totals(),
            'this_month' => AiUsage::totals(now()->startOfMonth()),
            'by_model' => AiUsage::byModel(now()->startOfMonth()->subMonths(2)),
            'as_of' => now()->toIso8601String(),
        ]);
    }

    /** Drop the cached snapshot so the next read recomputes. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
