<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the customer self-service portal. A visitor is "logged in" only by a
 * customer id stored in the session (put there by a valid magic link); anyone
 * else is sent to the sign-in screen. The resolved customer is shared on the
 * request so portal controllers never trust an id from the URL — every query is
 * scoped to this customer, so one customer can never read another's data.
 */
class EnsurePortalCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = ($id = $request->session()->get('portal.customer_id'))
            ? Customer::find($id)
            : null;

        if ($customer === null) {
            $request->session()->forget('portal.customer_id');

            return redirect()->route('portal.login');
        }

        $request->attributes->set('portalCustomer', $customer);

        return $next($request);
    }
}
