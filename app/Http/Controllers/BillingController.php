<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use Illuminate\View\View;

/**
 * Customer-facing billing entry points. Card capture itself happens entirely
 * on Cardcom's hosted (PCI Level 1) Low Profile page — we only embed it in an
 * iframe, so the customer stays on our page and no card data ever reaches us.
 */
class BillingController extends Controller
{
    /**
     * Show Cardcom's hosted card page embedded in an iframe so the customer can
     * enter/replace a card without leaving our site. This link is used both by
     * the signup flow and the card-update links in dunning messages (signed, so
     * it can't be enumerated to probe customer ids).
     */
    public function updateCard(Customer $customer, CardcomClient $cardcom): View
    {
        $lowProfile = $cardcom->createTokenLowProfile(
            $customer->id,
            route('billing.update-card.done', ['result' => 'success']),
            route('billing.update-card.done', ['result' => 'failed']),
            route('webhooks.cardcom', ['secret' => config('billing.cardcom.webhook_secret')]),
        );

        // Remember this session so the team can reconcile the card manually if
        // the completion webhook is lost (see the "sync card" panel action).
        if (! empty($lowProfile['low_profile_id'])) {
            $customer->update(['pending_card_lp_id' => $lowProfile['low_profile_id']]);
        }

        return view('billing.card-iframe', [
            'cardUrl' => $lowProfile['url'],
        ]);
    }
}
