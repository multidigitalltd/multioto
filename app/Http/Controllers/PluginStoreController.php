<?php

namespace App\Http\Controllers;

use App\Models\PluginOrder;
use App\Models\PluginProduct;
use App\Services\Licensing\PluginCheckout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The page a customer buys a plugin on, without us.
 *
 * Public, so everything here assumes a stranger: the form is validated, the
 * order is addressed by a random reference rather than a row id, and no page
 * shows anything about a purchase that is not the one the address names.
 */
class PluginStoreController extends Controller
{
    /** The sales page. */
    public function show(string $slug): View
    {
        $product = PluginProduct::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        abort_if(blank($product->price_agorot), 404);

        return view('store.plugin', ['product' => $product]);
    }

    /** Take the details and send them to the payment page. */
    public function buy(Request $request, string $slug): RedirectResponse
    {
        $product = PluginProduct::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'terms' => ['accepted'],
        ], [], [
            'name' => 'שם מלא',
            'email' => 'אימייל',
            'phone' => 'טלפון',
            'terms' => 'התנאים',
        ]);

        try {
            $purchase = app(PluginCheckout::class)->start(
                $product,
                trim($data['name']),
                trim($data['email']),
                filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            );
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                // The buyer is told the truth and what to do, not an apology:
                // a checkout that fails silently is a sale that becomes an email
                // asking whether anybody is there.
                ->withErrors(['name' => 'לא הצלחנו לפתוח את עמוד התשלום כרגע. נסו שוב בעוד רגע, או כתבו לנו ונשלים את הרכישה ידנית.']);
        }

        return redirect()->away($purchase['url']);
    }

    /**
     * After the payment page.
     *
     * The key may not be here yet: Cardcom sends the buyer back before its
     * webhook reaches us, and the licence is issued by the money arriving, not
     * by the browser returning. So the page says which of the two it is looking
     * at, and never implies a purchase failed just because it is a second early.
     */
    public function done(string $reference): View
    {
        $order = PluginOrder::query()->where('reference', $reference)->firstOrFail();

        return view('store.done', [
            'order' => $order->load(['product', 'license']),
        ]);
    }

    /**
     * The zip, for somebody who just bought it.
     *
     * Addressed by the order's own random reference — a paid order is proof of
     * purchase, and asking a buyer to activate a licence before they are allowed
     * to download the thing they need in order to activate it is a circle.
     * Refused for an order that is not paid.
     */
    public function download(string $reference): SymfonyResponse
    {
        $order = PluginOrder::query()->where('reference', $reference)->firstOrFail();

        abort_unless($order->isFulfilled(), 403, 'ההזמנה עדיין לא שולמה.');

        $release = $order->product?->currentRelease();

        abort_if($release === null, 404, 'אין כרגע גרסה זמינה להורדה.');

        $disk = Storage::disk((string) config('licensing.disk'));

        abort_unless($disk->exists($release->zip_path), 404, 'קובץ הגרסה חסר.');

        return $disk->download($release->zip_path, $order->product->slug.'-'.$release->number().'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }
}
