<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Customer;
use App\Support\CardLink;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        $openDebt = $this->openCharges($customer);

        return view('portal.dashboard', [
            'customer' => $customer,
            'subscriptions' => $subscriptions,
            'hasCard' => $customer->default_token_id !== null,
            'openTicketCount' => $customer->tickets()
                ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
                ->count(),
            'invoiceCount' => $customer->invoices()->count(),
            'openDebtCount' => $openDebt->count(),
            'openDebtAgorot' => (int) $openDebt->sum('total_agorot'),
        ]);
    }

    public function debt(Request $request): View
    {
        $customer = $this->customer($request);
        $charges = $this->openCharges($customer);

        return view('portal.debt', [
            'customer' => $customer,
            'charges' => $charges,
            'totalAgorot' => (int) $charges->sum('total_agorot'),
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

    /**
     * The customer's outstanding debt: pending charges for which a payment demand
     * was actually issued (same definition the collection forecast uses). Scoped
     * to charges owned by this customer directly OR through one of their
     * subscriptions — never by an id from the request.
     *
     * @return Collection<int, Charge>
     */
    private function openCharges(Customer $customer): Collection
    {
        return Charge::query()
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->where(fn (Builder $q) => $q
                ->where('customer_id', $customer->id)
                ->orWhereHas('subscription', fn (Builder $s) => $s->where('customer_id', $customer->id)))
            ->with('subscription.site')
            ->orderBy('created_at')
            ->get();
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('portalCustomer');

        return $customer;
    }
}
