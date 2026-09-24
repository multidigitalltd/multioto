<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\SiteAgentOrder;
use App\Models\SiteInstallation;
use App\Services\SiteAgent\SiteAgentCheckout;
use App\Services\SiteAgent\SiteAgentProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The page somebody buys סוכן האתר on, and the one they land on afterwards.
 *
 * Public, so everything here assumes a stranger: the form is validated, the
 * order is addressed by its own random reference rather than a row id, and no
 * page shows anything about a purchase other than the one the address names.
 *
 * The page after payment is the more important of the two. It is the only place
 * the connection codes are ever shown, and a buyer who closes it has to be able
 * to get back — so the address is durable and the same content is emailed.
 */
class SiteAgentStoreController extends Controller
{
    /** The sales page: what it does, what it costs, and the form. */
    public function show(SiteAgentProduct $product): View
    {
        $plans = Plan::query()->publiclySellable()->get();

        // A product that cannot send a verification code cannot onboard anybody:
        // the very first message to a new customer is the code, and without an
        // approved template Meta refuses it outright. Selling into that means
        // taking money for a number that never beeps.
        abort_if($plans->isEmpty() || ! $product->ready(), 404);

        return view('store.site-agent', ['plans' => $plans]);
    }

    /** Take the details and send them to the payment page. */
    public function buy(Request $request, SiteAgentProduct $product): RedirectResponse
    {
        abort_unless($product->ready(), 404);

        $data = $request->validate([
            // Must be a plan that is on sale right now. Without that check a
            // posted id could buy the agent on somebody's negotiated price.
            'plan' => ['required', Rule::exists('plans', 'id')
                ->where('active', true)
                ->where('is_public', true)
                ->where('includes_site_agent', true)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'manager_name' => ['nullable', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:190'],
            'install_mode' => ['required', Rule::in(SiteAgentOrder::INSTALL_MODES)],
            'terms' => ['accepted'],
        ], [], [
            'plan' => 'המסלול',
            'name' => 'שם מלא',
            'email' => 'אימייל',
            'phone' => 'מספר הוואטסאפ',
            'domain' => 'כתובת האתר',
            'install_mode' => 'ההתקנה',
            'terms' => 'התנאים',
        ]);

        try {
            $purchase = app(SiteAgentCheckout::class)->start(Plan::findOrFail($data['plan']), [
                'name' => trim($data['name']),
                'email' => trim($data['email']),
                'phone' => trim($data['phone']),
                'manager_name' => filled($data['manager_name'] ?? null) ? trim($data['manager_name']) : null,
                'domain' => trim($data['domain']),
                'install_mode' => $data['install_mode'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                // The truth and what to do, not an apology: a checkout that fails
                // silently is a sale that becomes an email asking whether anybody
                // is there.
                ->withErrors(['phone' => 'לא הצלחנו לפתוח את עמוד התשלום כרגע. בדקו שמספר הוואטסאפ תקין, נסו שוב בעוד רגע, או כתבו לנו ונשלים את הרכישה ידנית.']);
        }

        return redirect()->away($purchase['url']);
    }

    /**
     * After the payment page: the activation pack, or the truth about why not.
     *
     * The service may not be switched on yet. Cardcom sends the buyer back
     * before its webhook reaches us, and everything is granted by the money
     * arriving rather than by the browser returning — so the page says which of
     * the two it is looking at and never implies a purchase failed because it is
     * a second early.
     */
    public function done(string $reference): View
    {
        $order = SiteAgentOrder::query()->where('reference', $reference)->firstOrFail();

        $order->load(['plan', 'site', 'installation']);

        return view('store.site-agent-done', [
            'order' => $order,
            // Generated on read, and only once the order is paid. These are the
            // keys to a customer's website; an unpaid order showing them would be
            // handing them to whoever opened the payment page and walked away.
            'codes' => $order->isFulfilled() && $order->site !== null
                ? $order->site->ensureAgentCredentials()
                : null,
        ]);
    }

    /**
     * The plugin zip, for somebody who just bought the service.
     *
     * Addressed by the paid order's own reference. Asking a buyer to sign in
     * before they may download the thing they need in order to connect — the
     * step that creates the account they would be signing into — is a circle,
     * and it is the step where a new customer gives up.
     */
    public function downloadPlugin(string $reference): BinaryFileResponse
    {
        $order = SiteAgentOrder::query()->where('reference', $reference)->firstOrFail();

        abort_unless($order->isFulfilled(), 403, 'ההזמנה עדיין לא שולמה.');

        $version = (string) config('agent.plugin.current_version');
        $path = base_path("wordpress-plugin/releases/multioto-agent-{$version}.zip");

        abort_unless(is_file($path), 404, 'קובץ התוסף אינו זמין כרגע. כתבו לנו ונשלח אותו.');

        return response()->download($path, "multioto-agent-{$version}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * The access a customer hands over so we can install it for them.
     *
     * Posted from the page above, addressed by the same unguessable reference.
     * What arrives here is a way into their WordPress, so it goes straight into
     * the encrypted column and nowhere else — not into a flash message, not into
     * a validation error, and never back onto the screen.
     */
    public function handover(Request $request, string $reference): RedirectResponse
    {
        $order = SiteAgentOrder::query()->where('reference', $reference)->firstOrFail();

        abort_unless($order->isFulfilled() && $order->wantsUsToInstall(), 403);

        $data = $request->validate([
            'access_method' => ['required', Rule::in(SiteInstallation::ACCESS_METHODS)],
            'access_secret' => ['required', 'string', 'max:2000'],
            'access_note' => ['nullable', 'string', 'max:500'],
            'access_expires_at' => ['nullable', 'date', 'after:now'],
        ], [], [
            'access_method' => 'סוג הגישה',
            'access_secret' => 'פרטי הגישה',
            'access_expires_at' => 'תוקף',
        ]);

        $installation = SiteInstallation::firstOrCreate(
            ['site_agent_order_id' => $order->id],
            [
                'customer_id' => $order->customer_id,
                'site_id' => $order->site_id,
                'domain' => $order->domain,
                'state' => SiteInstallation::AWAITING_ACCESS,
            ],
        );

        // Handing access over again replaces what was there: a customer who
        // sends a second link has almost always revoked the first.
        $installation->forceFill([
            'access_method' => $data['access_method'],
            'access_secret' => trim($data['access_secret']),
            'access_note' => filled($data['access_note'] ?? null) ? trim($data['access_note']) : null,
            'access_expires_at' => $data['access_expires_at'] ?? null,
            'access_cleared_at' => null,
            'state' => SiteInstallation::READY,
        ])->save();

        return redirect()
            ->route('store.agent.done', ['reference' => $order->reference])
            ->with('handover', 'הגישה התקבלה. נתקין את התוסף ונעדכן אתכם כשהשירות יהיה פעיל.');
    }
}
