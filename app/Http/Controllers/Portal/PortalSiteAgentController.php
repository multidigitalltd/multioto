<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshSiteCapabilitiesJob;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'connections' => $this->agentSites($customer),
            'extraPrice' => $this->extraPriceAgorot($subscription, $customer),
        ]);
    }

    /**
     * Connecting one site: the plugin, the codes, and the guide — in the one
     * place a customer can always come back to.
     *
     * Until now the codes were shown only on the page after a self-serve
     * purchase. A customer who closed it, lost the email, or was set up by the
     * team had nowhere to find them, and every such install became an email
     * to us asking for the keys.
     *
     * The codes are the keys to the site, so they are shown only to the
     * customer who owns it, and only while the service is paid for.
     */
    public function connect(Request $request, Site $site): View
    {
        $customer = $this->customer($request);
        $this->authorizeSite($customer, $site);

        $entitled = app(SiteAgentAccess::class)->subscribed($customer);

        return view('portal.site-agent-connect', [
            'site' => $site,
            'entitled' => $entitled,
            'codes' => $entitled ? $site->ensureAgentCredentials() : null,
            'status' => self::connectionStatus($site),
        ]);
    }

    /** The plugin zip, for a signed-in customer whose service is paid for. */
    public function plugin(Request $request): BinaryFileResponse
    {
        abort_unless(app(SiteAgentAccess::class)->subscribed($this->customer($request)), 403, 'המנוי אינו פעיל.');

        $version = (string) config('agent.plugin.current_version');
        $path = base_path("wordpress-plugin/releases/multioto-agent-{$version}.zip");

        abort_unless(is_file($path), 404, 'קובץ התוסף אינו זמין כרגע. כתבו לנו ונשלח אותו.');

        return response()->download($path, "multioto-agent-{$version}.zip", ['Content-Type' => 'application/zip']);
    }

    /**
     * "בדקו שוב" — ask the site now instead of at the next scheduled check.
     *
     * Queued, never done inside the request: it is a call to the customer's own
     * server, which may be slow or behind a challenge page. Only a site that is
     * already switched on can be asked; before that, the plugin is what calls us.
     */
    public function check(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSite($this->customer($request), $site);

        if (! $site->mcp_enabled) {
            return back()->with('status', 'האתר עדיין לא דיווח שהתוסף הותקן. אחרי שמירת הקודים בתוסף זה קורה תוך דקה.');
        }

        RefreshSiteCapabilitiesJob::dispatch($site->id);

        return back()->with('status', 'בודקים את החיבור. רעננו את העמוד בעוד כחצי דקה.');
    }

    /**
     * Where a site stands, from not installed to answering.
     *
     * @return array{state: string, label: string, detail: string}
     */
    public static function connectionStatus(Site $site): array
    {
        if ($site->mcp_enabled && $site->mcp_last_seen_at !== null) {
            return ['state' => 'connected', 'label' => 'מחובר ✓',
                'detail' => 'הבוט מחובר לאתר'.($site->agent_plugin_version ? " (תוסף {$site->agent_plugin_version})" : '')
                    .'. נבדק לאחרונה '.$site->mcp_last_seen_at->diffForHumans().'.'];
        }

        if ($site->mcp_enabled) {
            return ['state' => 'checking', 'label' => 'בבדיקה',
                'detail' => 'התוסף הותקן, והפאנל עדיין לא הצליח לדבר איתו. אם זה נמשך יותר מכמה דקות — ראו "החיבור לא עובד?" במדריך.'];
        }

        if ($site->agent_plugin_version !== null) {
            return ['state' => 'pending', 'label' => 'ממתין להפעלה',
                'detail' => 'התוסף הותקן ודיווח לנו. הצוות שלנו יפעיל את החיבור — בדרך כלל באותו יום עסקים.'];
        }

        return ['state' => 'not_installed', 'label' => 'לא מחובר',
            'detail' => 'התוסף עדיין לא הותקן באתר, או שהקודים לא נשמרו בו.'];
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

        // The subscription that carries THIS site, not merely one this customer
        // holds: a number added to site B must raise the price of site B's
        // service, and billing it to site A's subscription is an invoice the
        // customer cannot reconcile against anything.
        $subscription = $billing->subscriptionForSite($customer, $site->id);

        // Only a live subscription may grow. Adding a number to a lapsed one
        // would raise the debt of a customer whose service is already off.
        if ($subscription === null || ! in_array($subscription->status, SiteAgentAccess::ENTITLING, true)) {
            return back()->withErrors(['phone' => 'אפשר להוסיף מספר רק כשהמנוי על האתר הזה פעיל. הסדירו את התשלום ונסו שוב.']);
        }

        // Null means the plan names no price for an extra number. Adding one
        // anyway would be giving away a paid seat quietly, for as long as
        // nobody notices.
        if ($this->extraPriceAgorot($subscription, $customer) === null) {
            return back()->withErrors(['phone' => 'המסלול הזה אינו כולל מספרים נוספים. כתבו לנו ונשדרג אתכם.']);
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

        $subscriber = DB::transaction(function () use ($existing, $subscription, $customer, $site, $phone, $data, $billing): SiteAgentSubscriber {
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

            $billing->recountManagerSeats($subscription);

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
        $subscription = $billing->subscriptionForSite($customer, $subscriber->site_id);

        DB::transaction(function () use ($subscriber, $subscription, $billing): void {
            $subscriber->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => 'הוסר על ידי הלקוח באזור האישי',
            ])->save();

            // Recounted, never decremented. A blind decrement removes a PAID
            // seat whichever number was revoked — so a customer who drops the
            // number their plan includes, while a paid extra keeps working,
            // silently stops being charged for it.
            if ($subscription !== null) {
                $billing->recountManagerSeats($subscription);
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
     * The sites this customer runs the agent on: every site one of their
     * numbers is bound to, whatever state that number is in.
     *
     * @return Collection<int, Site>
     */
    private function agentSites(Customer $customer): Collection
    {
        return Site::query()
            ->where('customer_id', $customer->id)
            ->whereIn('id', SiteAgentSubscriber::query()->where('customer_id', $customer->id)->select('site_id'))
            ->orderBy('domain')
            ->get(['id', 'domain', 'mcp_enabled', 'mcp_last_seen_at', 'agent_plugin_version']);
    }

    /** A site in the URL is checked against the signed-in customer, never trusted. */
    private function authorizeSite(Customer $customer, Site $site): void
    {
        abort_unless((int) $site->customer_id === (int) $customer->id, 404);
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
