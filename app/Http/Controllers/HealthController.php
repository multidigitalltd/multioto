<?php

namespace App\Http\Controllers;

use App\Services\System\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one question the system cannot answer about itself from the inside.
 *
 * The scheduler cannot report that the scheduler has stopped, and a queue with
 * no worker raises nothing at all — so an external monitor asks from outside,
 * and this is what it asks. Point any uptime service at it: 200 means the
 * machinery is running, 503 means a moving part has stopped.
 *
 * Without a token the answer is only that word. Details — which check failed
 * and since when — need ?token=<HEALTH_TOKEN>, because a public list of what
 * is broken is a shopping list for somebody else.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthReport $health): JsonResponse
    {
        $report = $health->collect();
        $code = $report['status'] === HealthReport::DOWN ? 503 : 200;

        if (! $this->authorised($request)) {
            return response()->json(['status' => $report['status']], $code);
        }

        return response()->json([
            'status' => $report['status'],
            'checked_at' => now()->toIso8601String(),
            'checks' => $report['checks'],
        ], $code);
    }

    /** Constant-time comparison, so the token cannot be guessed a character at a time. */
    private function authorised(Request $request): bool
    {
        $expected = trim((string) config('health.token'));

        if ($expected === '') {
            return false;
        }

        $given = (string) ($request->query('token') ?? $request->header('X-Health-Token') ?? '');

        return $given !== '' && hash_equals($expected, $given);
    }
}
