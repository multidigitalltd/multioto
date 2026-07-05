<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Http\RedirectResponse;

/**
 * Customer-facing billing entry points. Card capture itself happens entirely
 * on Cardcom's hosted (PCI Level 1) Low Profile page — we only redirect.
 */
class BillingController extends Controller
{
    /**
     * Send the customer to Cardcom's hosted page to enter/replace a card.
     * This link is embedded in dunning messages (signed, so it can't be
     * enumerated to probe customer ids).
     */
    public function updateCard(Customer $customer, CardcomClient $cardcom): RedirectResponse
    {
        $lowProfile = $cardcom->createTokenLowProfile(
            $customer->id,
            route('billing.update-card.done', ['result' => 'success']),
            route('billing.update-card.done', ['result' => 'failed']),
            route('webhooks.cardcom', ['secret' => config('billing.cardcom.webhook_secret')]),
        );

        return redirect()->away($lowProfile['url']);
    }
}
