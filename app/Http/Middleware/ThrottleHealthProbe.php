<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting for /health that does not depend on the database.
 *
 * The ordinary `throttle` middleware counts hits in the default cache store,
 * which in this application is the database — so the one endpoint whose job is
 * to report that the database has stopped would be counted, and blocked, by the
 * database. During a silent timeout the probe would hang in middleware and the
 * monitor would see a gateway timeout instead of the 503 naming the broken part.
 *
 * A cache that cannot be reached fails OPEN here: a limiter is a courtesy, and
 * refusing to answer "is the system alive" because the counter is unavailable
 * would trade a small abuse risk for the exact blindness this endpoint exists
 * to prevent. The response it guards is a few bytes and touches no external
 * service.
 */
class ThrottleHealthProbe
{
    public function handle(Request $request, Closure $next, int $perMinute = 60): Response
    {
        $key = 'health-probe:'.sha1((string) $request->ip());

        $hits = rescue(function () use ($key): int {
            $store = Cache::store(config('health.throttle_store'));
            $hits = (int) $store->get($key, 0) + 1;
            $store->put($key, $hits, now()->addMinute());

            return $hits;
        }, 0, report: false);

        if ($perMinute > 0 && $hits > $perMinute) {
            return response()->json(['status' => 'throttled'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $next($request);
    }
}
