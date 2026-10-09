<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SiteAgentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A private, complete price review; approval stays in the requesting WhatsApp conversation. */
class PortalSiteAgentChangeController extends Controller
{
    public function __invoke(Request $request, int $change): Response
    {
        $customer = $request->attributes->get('portalCustomer');
        $proposal = SiteAgentRequest::query()
            ->with('site:id,domain,customer_id')
            ->whereKey($change)
            ->where('customer_id', $customer->id)
            ->whereHas('site', fn ($query) => $query->where('customer_id', $customer->id))
            ->where('operation', SiteAgentRequest::OP_CATEGORY_SALE)
            ->firstOrFail();
        $review = data_get($proposal->plan, 'category_sale_review');
        abort_unless(is_array($review) && is_array($review['products'] ?? null), 404);
        $zone = new \DateTimeZone(data_get($review, 'schedule.timezone', 'UTC'));
        foreach ($review['products'] as &$product) {
            foreach (['before', 'after'] as $side) {
                foreach (['sale_from', 'sale_to'] as $date) {
                    $value = $product[$side][$date] ?? null;
                    if (is_int($value)) {
                        $product[$side][$date] = (new \DateTimeImmutable('@'.$value))->setTimezone($zone)->format('d/m/Y H:i P');
                    }
                }
            }
        }
        unset($product);

        $state = $proposal->state === SiteAgentRequest::AWAITING && $proposal->expires_at?->isPast()
            ? SiteAgentRequest::EXPIRED : $proposal->state;
        $status = match ($state) {
            SiteAgentRequest::AWAITING => 'ממתינה לאישור',
            SiteAgentRequest::APPLYING => 'בביצוע',
            SiteAgentRequest::APPLIED => 'בוצעה',
            SiteAgentRequest::REVERTED => 'שוחזרה',
            SiteAgentRequest::CANCELED => 'בוטלה',
            SiteAgentRequest::EXPIRED => 'פג תוקף ההצעה',
            default => 'לא בוצעה',
        };

        return response()->view('portal.site-agent-change', compact('customer', 'proposal', 'review', 'state', 'status'))
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
