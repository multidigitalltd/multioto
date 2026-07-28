<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Support\MarketingPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

/**
 * The customer-facing "הסירו אותי מרשימת הדיוור" page.
 *
 * Reached only through a signed link carried in a marketing message, so the
 * customer id cannot be swapped to unsubscribe somebody else. The opt-out is
 * applied on GET, deliberately: the law requires the link to work, and making
 * the customer find and press a second button is exactly the friction it
 * exists to prevent. An undo is offered on the confirmation page for the
 * accidental click.
 */
class MarketingPreferencesController extends Controller
{
    public function __construct(private MarketingPreferences $preferences) {}

    public function unsubscribe(Customer $customer): View
    {
        $this->preferences->optOut($customer, 'קישור במייל');

        return view('marketing.unsubscribed', [
            'customer' => $customer,
            // The undo is posted, so this signed URL is the form's action.
            // Time-limited on purpose: unlike the opt-out, an undo does not
            // need to work years later, and a short window limits what a
            // forwarded confirmation page can do.
            'resubscribeUrl' => URL::temporarySignedRoute(
                'marketing.resubscribe', now()->addDays(30), ['customer' => $customer->getKey()],
            ),
        ]);
    }

    public function resubscribe(Customer $customer): RedirectResponse
    {
        $this->preferences->optIn($customer, 'קישור במייל');

        return redirect()->route('marketing.resubscribed');
    }

    public function resubscribed(): View
    {
        return view('marketing.resubscribed');
    }
}
