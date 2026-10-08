<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apply the panel's module grants to team routes outside Filament. */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($request->user()?->canAccessModule($module) ?? false, 403);

        return $next($request);
    }
}
