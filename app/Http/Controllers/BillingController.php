<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use App\Support\CardcomWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        try {
            $lowProfile = $cardcom->createTokenLowProfile(
                $customer->id,
                route('billing.update-card.done', ['result' => 'success']),
                route('billing.update-card.done', ['result' => 'failed']),
                CardcomWebhook::url(),
            );
        } catch (\Throwable $e) {
            Log::error('updateCard: Cardcom token page creation threw', [
                'customer_id' => $customer->id,
                'error' => Str::limit($e->getMessage(), 300),
            ]);

            return view('billing.card-error');
        }

        $cardUrl = (string) ($lowProfile['url'] ?? '');

        // Only ever frame a real Cardcom https page. An empty/invalid URL means
        // Cardcom rejected the request (logged in the client) — show a clear
        // message instead of embedding a broken 404 the customer can't act on.
        if (! Str::startsWith($cardUrl, 'https://')) {
            return view('billing.card-error');
        }

        // Remember this session so the team can reconcile the card manually if
        // the completion webhook is lost (see the "sync card" panel action).
        if (! empty($lowProfile['low_profile_id'])) {
            $customer->update(['pending_card_lp_id' => $lowProfile['low_profile_id']]);
        }

        return view('billing.card-iframe', [
            'cardUrl' => $cardUrl,
        ]);
    }
}
