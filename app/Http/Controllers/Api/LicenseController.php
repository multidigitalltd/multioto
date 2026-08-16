<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\Licensing\DownloadLink;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The licence server our plugins talk to. The contract is docs/license-api.md
 * and this file must not drift from it — on the other side of it are shops we
 * do not control and cannot redeploy.
 *
 * Two rules run through all of it:
 *
 *  · **A business answer is a 200.** "Expired", "over the site limit" and
 *    "unknown key" are answers, not failures. The plugin treats 5xx and dropped
 *    connections as a network fault and keeps whatever state it had, so a shop
 *    never loses its licence over a bad minute at our end. Answering "not
 *    valid" with an error code would make an outage look like a mass
 *    revocation.
 *
 *  · **The key is the whole authentication.** There are no tokens and no
 *    headers, so nothing here may reveal which keys exist: every refusal reads
 *    the same from outside.
 */
class LicenseController extends Controller
{
    public function __construct(private LicenseService $licenses) {}

    public function activate(Request $request): JsonResponse
    {
        [$key, $site, $version] = $this->credentials($request);

        return response()->json($this->licenses->activate($key, $site, $version));
    }

    public function check(Request $request): JsonResponse
    {
        [$key, $site, $version] = $this->credentials($request);

        return response()->json($this->licenses->check($key, $site, $version));
    }

    public function deactivate(Request $request): JsonResponse
    {
        [$key, $site] = $this->credentials($request);

        return response()->json($this->licenses->deactivate($key, $site));
    }

    /**
     * What version is available, and where to get it.
     *
     * The only endpoint that refuses with a status code: 403 when the licence
     * does not cover this shop. The plugin reads that as "no update for you"
     * and shows nothing, which is exactly right — an expired licence must not
     * advertise an update the shop cannot install.
     */
    public function update(Request $request): JsonResponse
    {
        [$key, $site] = $this->credentials($request);

        $answer = $this->licenses->check($key, $site, $request->string('version')->toString() ?: null);

        if ($answer['status'] !== LicenseService::VALID) {
            return response()->json([
                'status' => $answer['status'],
                'message' => $answer['message'] ?: 'הרישיון אינו מכסה את האתר הזה, ולכן אין עדכון זמין.',
            ], 403);
        }

        $license = License::findByKey($key);
        $product = $license?->product;
        $release = $product?->currentRelease();

        // A product with nothing published is not an error — there is simply no
        // update. Saying so with a 403 would be a lie about the licence.
        //
        // Neither is a licence bought outright WITHOUT updates: it is valid, the
        // customer owns the plugin, and there is simply never a newer version for
        // them. A 403 here would make the plugin report a licence problem for a
        // product that is working exactly as sold.
        if ($product === null || $release === null || ! $license->includesUpdates()) {
            return response()->json([
                'status' => LicenseService::VALID,
                'message' => '',
            ]);
        }

        return response()->json([
            'version' => $release->number(),
            'download_url' => DownloadLink::url($license, $site),
            'homepage' => (string) ($product->homepage ?? ''),
            'requires' => $product->requires(),
            'requires_php' => $product->requiresPhp(),
            'tested' => $product->tested(),
            'changelog' => (string) ($release->changelog ?? ''),
            'last_updated' => ($release->released_at ?? $release->updated_at)?->format('Y-m-d H:i:s') ?? '',
        ]);
    }

    /**
     * Serve the zip.
     *
     * WordPress arrives here on its own, carrying only what the signed link put
     * in the address. Everything is re-checked at this moment — the signature,
     * the expiry, and that the licence still covers this shop — because the link
     * was made up to an hour ago and a licence can be revoked in between.
     */
    public function download(Request $request): SymfonyResponse
    {
        $license = DownloadLink::verify(
            $request->string('k')->toString(),
            $request->string('site')->toString(),
            (int) $request->integer('exp'),
            $request->string('sig')->toString(),
        );

        // One answer for every refusal: a forged signature, an expired link, a
        // revoked licence and a shop that was released all look the same from
        // outside. Distinguishing them would be a way to enumerate keys.
        abort_if($license === null, 403, 'קישור ההורדה אינו תקף.');

        $release = $license->product?->currentRelease();

        abort_if($release === null, 404, 'אין גרסה זמינה להורדה.');

        $disk = Storage::disk((string) config('licensing.disk'));

        abort_unless($disk->exists($release->zip_path), 404, 'קובץ הגרסה חסר.');

        $name = ($license->product->slug ?? 'plugin').'-'.$release->number().'.zip';

        return $disk->download($release->zip_path, $name, ['Content-Type' => 'application/zip']);
    }

    /**
     * The three fields every call carries. Read leniently and trimmed: a key
     * pasted with a stray space is the customer's most common mistake and is
     * not worth a support ticket.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function credentials(Request $request): array
    {
        return [
            trim($request->string('key')->toString()),
            trim($request->string('site')->toString()),
            trim($request->string('version')->toString()) ?: null,
        ];
    }
}
