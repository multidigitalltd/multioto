<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
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

        $hits = rescue(fn (): int => $this->count($key), 0, report: false);

        if ($perMinute > 0 && $hits > $perMinute) {
            return response()->json(['status' => 'throttled'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $next($request);
    }

    /**
     * One hit, counted so that concurrent requests cannot overwrite each other.
     *
     * Read-then-write is not enough: a burst from one address would have every
     * request read the same old value and write back the same new one, so the
     * counter would crawl while the requests all went through — precisely the
     * case a limiter exists for. The update is therefore serialised under the
     * store's own lock where it offers one (the file store does).
     */
    private function count(string $key): int
    {
        $cache = Cache::store(config('health.throttle_store'));

        if ($cache->getStore() instanceof LockProvider) {
            return $cache->lock($key.':lock', 5)->block(1, fn (): int => $this->bump($cache, $key));
        }

        return $this->bump($cache, $key);
    }

    /** The counter itself, always with a fresh one-minute window on first use. */
    private function bump(Repository $cache, string $key): int
    {
        $cache->add($key, 0, now()->addMinute());

        return (int) $cache->increment($key);
    }
}
