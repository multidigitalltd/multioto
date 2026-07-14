<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\CardLink;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signed-in customer's self-service pages. Every query is scoped to the
 * customer resolved by EnsurePortalCustomer (never an id from the request), so
 * a customer only ever sees their own subscriptions, invoices and tickets.
 */
class PortalController extends Controller
{
    public function dashboard(Request $request): View
    {
        $customer = $this->customer($request);

        $subscriptions = $customer->subscriptions()
            ->with(['plan', 'site'])
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue])
            ->orderByDesc('id')
            ->get();

        return view('portal.dashboard', [
            'customer' => $customer,
            'subscriptions' => $subscriptions,
            'hasCard' => $customer->default_token_id !== null,
            'openTicketCount' => $customer->tickets()
                ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
                ->count(),
            'invoiceCount' => $customer->invoices()->count(),
        ]);
    }

    public function invoices(Request $request): View
    {
        $customer = $this->customer($request);

        return view('portal.invoices', [
            'customer' => $customer,
            'invoices' => $customer->invoices()->latest('issued_at')->latest('id')->get(),
        ]);
    }

    public function tickets(Request $request): View
    {
        $customer = $this->customer($request);

        return view('portal.tickets', [
            'customer' => $customer,
            'tickets' => $customer->tickets()->latest('updated_at')->limit(50)->get(),
        ]);
    }

    /**
     * Send the customer to the existing signed card-capture page (Cardcom's
     * hosted PCI page). We reuse the same helper the panel and dunning use, so
     * card handling stays in exactly one place.
     */
    public function updateCard(Request $request): RedirectResponse
    {
        return redirect()->away(CardLink::for($this->customer($request)->id));
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('portalCustomer');

        return $customer;
    }
}
