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
    /**
     * The path this endpoint lives at.
     *
     * Named here because more than the route needs to recognise it: work that
     * every other request does during boot — reading the settings table, for
     * one — has to be skipped for this one, or the request that exists to
     * report a stopped database would wait on the database before it ever
     * reached this class.
     */
    public const PATH = 'health';

    /**
     * Is the request being handled right now the probe?
     *
     * Asked by the providers that do database work while EVERY request boots —
     * the settings overlay, the panel's notifications-table guard. On this one
     * path they skip it: a database that times out rather than refuses would
     * otherwise burn its connect timeout there, before routing, and the monitor
     * would get a gateway timeout instead of the 503 naming the broken part.
     * Console runs (workers, scheduler) are never the probe.
     */
    public static function isProbe(): bool
    {
        return ! app()->runningInConsole()
            && app()->bound('request')
            && app('request')->path() === self::PATH;
    }

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
