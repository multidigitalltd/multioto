<?php

namespace App\Services\Billing;

use App\Enums\ChargeStatus;
use App\Enums\MessageDirection;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Collection;

/**
 * רווחיות פר לקוח: כמה כל לקוח מכניס מול כמה עבודה הוא צורך. ההכנסה נמדדת
 * מחיובים שהצליחו בחלון הנבחר; העומס מוערך בדקות טיפול לפי משקלים מתצורה
 * (פנייה חדשה, הודעת לקוח נכנסת, תקלת אתר), ומתורגם לעלות לפי תעריף שעה —
 * כך שמתקבל רווח משוער ושולי רווח, והלקוחות שאוכלים את העסק צפים למעלה.
 * הכול בשאילתות מקובצות — ללא N+1.
 */
class ProfitabilityReport
{
    /**
     * Per-customer profitability rows for the trailing window, worst first.
     *
     * @return Collection<int, array{
     *   customer_id: int, name: string, revenue_agorot: int,
     *   tickets: int, messages: int, incidents: int,
     *   minutes: int, cost_agorot: int, profit_agorot: int, margin: ?float
     * }>
     */
    public function rows(int $windowDays): Collection
    {
        $since = now()->subDays($windowDays);

        // Revenue attributed to the charge's own customer or, failing that, the
        // subscription's customer — the same ownership rule the portal uses.
        // Windowed by the PAYMENT time (charged_at — when the money actually
        // arrived, the same field the collections stats use), so a demand opened
        // months ago but paid inside the window still counts; created_at is only
        // a fallback for legacy rows that predate the charged_at stamp.
        $revenue = Charge::query()
            ->leftJoin('subscriptions', 'subscriptions.id', '=', 'charges.subscription_id')
            ->where('charges.status', ChargeStatus::Succeeded)
            ->whereRaw('COALESCE(charges.charged_at, charges.created_at) >= ?', [$since])
            ->whereRaw('COALESCE(charges.customer_id, subscriptions.customer_id) IS NOT NULL')
            ->selectRaw('COALESCE(charges.customer_id, subscriptions.customer_id) as cid')
            ->selectRaw('SUM(charges.total_agorot) as total')
            ->groupBy('cid')
            ->pluck('total', 'cid');

        $tickets = Ticket::query()
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $since)
            ->selectRaw('customer_id, COUNT(*) as c')
            ->groupBy('customer_id')
            ->pluck('c', 'customer_id');

        $messages = TicketMessage::query()
            ->join('tickets', 'tickets.id', '=', 'ticket_messages.ticket_id')
            ->whereNotNull('tickets.customer_id')
            ->where('ticket_messages.direction', MessageDirection::Inbound)
            ->where('ticket_messages.created_at', '>=', $since)
            ->selectRaw('tickets.customer_id as cid, COUNT(*) as c')
            ->groupBy('cid')
            ->pluck('c', 'cid');

        $incidents = Incident::query()
            ->join('sites', 'sites.id', '=', 'incidents.site_id')
            ->whereNotNull('sites.customer_id')
            ->where('incidents.started_at', '>=', $since)
            ->selectRaw('sites.customer_id as cid, COUNT(*) as c')
            ->groupBy('cid')
            ->pluck('c', 'cid');

        $ids = collect($revenue->keys())
            ->merge($tickets->keys())->merge($messages->keys())->merge($incidents->keys())
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $names = Customer::whereIn('id', $ids)->pluck('name', 'id');

        $perTicket = (int) config('billing.profitability.minutes_per_ticket', 30);
        $perMessage = (int) config('billing.profitability.minutes_per_message', 4);
        $perIncident = (int) config('billing.profitability.minutes_per_incident', 20);
        $hourlyCost = (int) config('billing.profitability.hourly_cost_agorot', 15000);

        return $ids
            ->map(function (int $id) use ($revenue, $tickets, $messages, $incidents, $names, $perTicket, $perMessage, $perIncident, $hourlyCost): array {
                $rev = (int) ($revenue[$id] ?? 0);
                $t = (int) ($tickets[$id] ?? 0);
                $m = (int) ($messages[$id] ?? 0);
                $i = (int) ($incidents[$id] ?? 0);

                $minutes = $t * $perTicket + $m * $perMessage + $i * $perIncident;
                $cost = (int) round($minutes / 60 * $hourlyCost);
                $profit = $rev - $cost;

                return [
                    'customer_id' => $id,
                    'name' => (string) ($names[$id] ?? "לקוח #{$id}"),
                    'revenue_agorot' => $rev,
                    'tickets' => $t,
                    'messages' => $m,
                    'incidents' => $i,
                    'minutes' => $minutes,
                    'cost_agorot' => $cost,
                    'profit_agorot' => $profit,
                    'margin' => $rev > 0 ? round($profit / $rev * 100, 1) : null,
                ];
            })
            // Worst first — the customers eating the business at the top.
            ->sortBy('profit_agorot')
            ->values();
    }
}
