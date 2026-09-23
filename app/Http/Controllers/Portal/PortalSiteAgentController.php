<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\WhatsAppCloudClient;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * הסוכן שלי — the numbers that drive this customer's sites, and adding another.
 *
 * The product is sold per site and used per person, and those are not the same
 * number. A shop has an owner and an office manager; an agency has the client
 * and the person who actually maintains the site. Until now a second number
 * meant an email to us, so in practice the product was one number per customer
 * and the second person kept sending screenshots to the first.
 *
 * Adding one here charges for it. It is an upgrade to the subscription they
 * already hold rather than a new purchase: one charge, one invoice, one dunning
 * ladder. A customer must not be able to fall behind on half a service.
 *
 * Every query starts from the customer resolved by EnsurePortalCustomer, never
 * from an id in the URL.
 */
class PortalSiteAgentController extends Controller
{
    public function index(Request $request, SiteAgentBilling $billing): View
    {
        $customer = $this->customer($request);
        $subscription = $billing->subscriptionFor($customer);

        return view('portal.site-agent', [
            'customer' => $customer,
            'subscription' => $subscription,
            'entitled' => app(SiteAgentAccess::class)->subscribed($customer),
            'numbers' => $this->numbers($customer),
            'sites' => $this->sites($customer),
            'extraPrice' => $this->extraPriceAgorot($subscription, $customer),
        ]);
    }

    /**
     * Add another manager's number, and pay for it.
     *
     * The charge is not taken now. The number joins the subscription and the
     * next ordinary cycle collects the higher amount — which is also why the
     * page says so in the same sentence as the price. Taking a pro-rated payment
     * here would mean a second card page, a second invoice and a second thing
     * that can fail, for a few days of one line item.
     */
    public function addNumber(Request $request, SiteAgentBilling $billing, WhatsAppCloudClient $whatsapp): RedirectResponse
    {
        $customer = $this->customer($request);
        $subscription = $billing->subscriptionFor($customer);

        // Only a live subscription may grow. Adding a number to a lapsed one
        // would raise the debt of a customer whose service is already off.
        if ($subscription === null || ! in_array($subscription->status, SiteAgentAccess::ENTITLING, true)) {
            return back()->withErrors(['phone' => 'אפשר להוסיף מספר רק כשהמנוי פעיל. הסדירו את התשלום ונסו שוב.']);
        }

        $price = $this->extraPriceAgorot($subscription, $customer);

        // Null means the plan names no price for an extra number. Adding one
        // anyway would be giving away a paid seat quietly, for as long as
        // nobody notices.
        if ($price === null) {
            return back()->withErrors(['phone' => 'המסלול הזה אינו כולל מספרים נוספים. כתבו לנו ונשדרג אתכם.']);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:120'],
            // The site has to be one of theirs — checked below against the
            // customer, not just for existing.
            'site_id' => ['required', 'integer'],
            'confirm' => ['accepted'],
        ], [], [
            'phone' => 'מספר הוואטסאפ',
            'site_id' => 'האתר',
            'confirm' => 'אישור התוספת לחיוב',
        ]);

        $site = Site::query()
            ->where('customer_id', $customer->id)
            ->find($data['site_id']);

        if ($site === null) {
            return back()->withErrors(['site_id' => 'האתר אינו שלכם.']);
        }

        $phone = $whatsapp->normalize($data['phone']);

        if ($phone === '') {
            return back()->withErrors(['phone' => 'מספר הוואטסאפ אינו תקין.']);
        }

        // A number already bound to this site is not a sale. Re-binding it must
        // not raise the price a second time — that is a customer paying twice
        // for the manager they already had, discovered on the next invoice.
        $existing = SiteAgentSubscriber::query()
            ->where('site_id', $site->id)
            ->where('phone', $phone)
            ->first();

        if ($existing !== null && $existing->revoked_at === null) {
            return back()->withErrors(['phone' => 'המספר הזה כבר מנהל את האתר הזה.']);
        }

        $subscriber = DB::transaction(function () use ($existing, $subscription, $customer, $site, $phone, $data): SiteAgentSubscriber {
            $subscriber = $existing ?? new SiteAgentSubscriber([
                'phone' => $phone,
                'site_id' => $site->id,
            ]);

            $subscriber->fill([
                'customer_id' => $customer->id,
                'site_id' => $site->id,
                'name' => filled($data['name'] ?? null) ? $data['name'] : $subscriber->name,
            ])->forceFill([
                'phone' => $phone,
                'revoked_at' => null,
                'revoked_reason' => null,
            ])->save();

            $subscription->increment('agent_extra_numbers');

            return $subscriber;
        });

        SendSiteAgentVerificationJob::dispatch($subscriber->id);

        SystemLog::record('info', 'siteagent',
            "הלקוח {$customer->name} הוסיף מספר מנהל לאתר {$site->domain}",
            ['customer_id' => $customer->id, 'site_id' => $site->id]);

        return back()->with('status', 'המספר נוסף ונשלח אליו קוד אימות בוואטסאפ. '
            .'עליו להשיב על הקוד באותה שיחה כדי שיוכל לנהל את האתר.');
    }

    /**
     * Send the six-digit code again.
     *
     * The commonest reason a purchase never becomes a working service: a code
     * that arrived while somebody was driving. Throttled at the route, because
     * each press is a message to a phone.
     */
    public function resend(Request $request, SiteAgentSubscriber $subscriber): RedirectResponse
    {
        $this->authorizeSubscriber($request, $subscriber);

        if ($subscriber->verified_at !== null) {
            return back()->with('status', 'המספר כבר מאומת.');
        }

        // The spent attempts go with the new code. Otherwise a customer who
        // mistyped five times is locked out of their own service for good, by a
        // counter that exists to stop a stranger guessing.
        $subscriber->forceFill(['verification_attempts' => 0])->save();

        SendSiteAgentVerificationJob::dispatch($subscriber->id);

        return back()->with('status', 'נשלח קוד חדש למספר '.$subscriber->phone.'.');
    }

    /**
     * Take a number's access away, and stop paying for it.
     *
     * The seat is released in the same transaction. A customer who removes a
     * manager and keeps being charged for them has been charged for nothing,
     * and they will find out on an invoice rather than here.
     */
    public function revoke(Request $request, SiteAgentBilling $billing, SiteAgentSubscriber $subscriber): RedirectResponse
    {
        $this->authorizeSubscriber($request, $subscriber);

        if ($subscriber->revoked_at !== null) {
            return back()->with('status', 'המספר כבר אינו מנהל את האתר.');
        }

        $customer = $this->customer($request);
        $subscription = $billing->subscriptionFor($customer);

        DB::transaction(function () use ($subscriber, $subscription): void {
            $subscriber->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => 'הוסר על ידי הלקוח באזור האישי',
            ])->save();

            // Never below zero: the first number is included in the plan, and a
            // customer who removes everybody must not end up with a credit that
            // makes the next invoice smaller than the plan's own price.
            if ($subscription !== null && $subscription->agent_extra_numbers > 0) {
                $subscription->decrement('agent_extra_numbers');
            }
        });

        return back()->with('status', 'המספר הוסר ולא יחויב מהמחזור הבא.');
    }

    /**
     * Every number this customer has, with its site.
     *
     * @return Collection<int, SiteAgentSubscriber>
     */
    private function numbers(Customer $customer): Collection
    {
        return SiteAgentSubscriber::query()
            ->where('customer_id', $customer->id)
            ->with('site:id,domain,mcp_enabled,mcp_endpoint')
            ->orderBy('revoked_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array<int, string> */
    private function sites(Customer $customer): array
    {
        return Site::query()
            ->where('customer_id', $customer->id)
            ->orderBy('domain')
            ->pluck('domain', 'id')
            ->all();
    }

    /**
     * What one more number costs on this customer's subscription, per cycle,
     * VAT as they pay it — or null when the plan does not sell them.
     */
    private function extraPriceAgorot(?Subscription $subscription, Customer $customer): ?int
    {
        return $subscription?->plan?->extraNumberGrossAgorot((bool) $customer->vat_exempt);
    }

    /** The label the buttons and the confirmation both use, said once. */
    public static function priceLabel(?int $agorot): string
    {
        if ($agorot === null) {
            return '—';
        }

        return $agorot === 0 ? 'ללא תוספת תשלום' : Money::ils($agorot);
    }

    /**
     * A number belongs to the signed-in customer, or it does not exist.
     *
     * Checked on the customer rather than on the site, because the binding
     * carries both and they can only ever be the same — the checkout and the
     * activation screen both write them together for exactly this reason.
     */
    private function authorizeSubscriber(Request $request, SiteAgentSubscriber $subscriber): void
    {
        abort_unless($subscriber->customer_id === $this->customer($request)->id, 404);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('portalCustomer');
    }
}
