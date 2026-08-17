<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\License;
use App\Models\LicenseSite;
use App\Models\SystemLog;
use App\Services\Licensing\LicenseIssuer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The customer's own licences: which shops they run on, and how to move one.
 *
 * This page exists because of the seat that cannot be freed. A shop is
 * rebuilt, migrated, or simply deleted, and the licence still counts it — the
 * plugin that would have released it is gone with the site. Until now the only
 * way out was an email to us, which meant every server move became a support
 * ticket and, until somebody answered it, a customer who had paid for three
 * shops and could install on none.
 *
 * Every query starts from the customer resolved by EnsurePortalCustomer, never
 * from an id in the URL, so one customer can neither read nor release another's
 * licence.
 */
class PortalLicenseController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $this->customer($request);

        return view('portal.licenses', [
            'customer' => $customer,
            'licenses' => $customer->licenses()
                ->with(['product', 'sites', 'deliveredRelease'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * Release one shop from a licence, freeing the seat.
     *
     * The same operation the plugin performs when it is deactivated, done from
     * the outside for the case the plugin can no longer do it — which is most of
     * the cases that matter.
     */
    public function releaseSite(Request $request, License $license, LicenseSite $site): RedirectResponse
    {
        $this->authorizeLicense($request, $license);

        // Belonging to the customer is not enough: the seat has to belong to
        // THIS licence, or a customer with two licences could free a seat off
        // the wrong one.
        abort_unless($site->license_id === $license->id, 404);

        $url = $site->site_url;
        $site->delete();

        SystemLog::record('info', 'licensing',
            "הלקוח שחרר את {$url} מרישיון {$license->key_prefix}… דרך האזור האישי",
            ['license_id' => $license->id, 'customer_id' => $license->customer_id]);

        return redirect()
            ->route('portal.licenses')
            ->with('status', "האתר {$url} שוחרר. אפשר להפעיל את הרישיון באתר אחר. התוסף באתר ששוחרר ימשיך לעבוד, אך יפסיק לקבל עדכונים.");
    }

    /**
     * Issue a replacement key.
     *
     * Not "send me my key again" — that is impossible, and the button says so
     * before it is pressed. We never stored the key, only its HMAC, so the only
     * thing that can be delivered is a new one, and the old one dies the moment
     * it is made.
     */
    public function regenerateKey(Request $request, License $license, LicenseIssuer $issuer): RedirectResponse
    {
        $this->authorizeLicense($request, $license);

        if ($license->isRevoked()) {
            return redirect()->route('portal.licenses')
                ->with('status', 'הרישיון מבוטל ולא ניתן להנפיק לו מפתח חדש. אם זו טעות — פנו אלינו.');
        }

        $sent = $issuer->reissue($license);

        return redirect()->route('portal.licenses')->with('status', $sent
            ? 'מפתח חדש נשלח לכתובת '.$license->email.'. המפתח הקודם הפסיק לפעול — יש להזין את החדש בכל אתר.'
            : 'לא הצלחנו לשלוח את המפתח: אין כתובת אימייל על הרישיון. פנו אלינו ונשלים.');
    }

    /**
     * Download the build this licence is entitled to.
     *
     * Served straight from the signed-in session rather than through the signed
     * link the plugin uses: that link is bound to a registered shop because
     * WordPress fetches it with no session, and a customer downloading by hand
     * has one.
     */
    public function download(Request $request, License $license): SymfonyResponse
    {
        $this->authorizeLicense($request, $license);

        $release = $license->entitledRelease();

        abort_if($release === null, 404, 'אין לנו רישום של הגרסה שנמסרה ברישיון הזה. פנו אלינו ונשלח אותה.');

        $disk = Storage::disk((string) config('licensing.disk'));

        abort_unless($disk->exists($release->zip_path), 404, 'קובץ הגרסה חסר. פנו אלינו.');

        return $disk->download(
            $release->zip_path,
            ($license->product?->slug ?? 'plugin').'-'.$release->number().'.zip',
            ['Content-Type' => 'application/zip'],
        );
    }

    /**
     * The licence must belong to the signed-in customer. A licence belonging to
     * somebody else is answered with the same 404 as one that does not exist —
     * telling the two apart would confirm which ids are real.
     */
    private function authorizeLicense(Request $request, License $license): void
    {
        abort_unless($license->customer_id === $this->customer($request)->id, 404);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('portalCustomer');
    }
}
