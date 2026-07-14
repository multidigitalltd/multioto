<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request coming FROM a site's companion plugin, by the
 * per-site bearer token it presents (matched against the stored hash). The
 * resolved site is shared on the request so the controller never trusts an id
 * from the payload. Returns 401 for any missing/unknown token.
 */
class AuthenticatesAgentSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $site = $token !== null ? Site::forAgentToken($token) : null;

        if ($site === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('agentSite', $site);

        return $next($request);
    }
}
