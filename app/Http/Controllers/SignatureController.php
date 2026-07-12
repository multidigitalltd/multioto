<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a customer's stored signup signature (the consent record) to a
 * signed-in team member. Behind panel auth — never public. Served inline as a
 * PNG with `nosniff` so the bytes are only ever treated as an image.
 */
class SignatureController extends Controller
{
    public function __invoke(Customer $customer): StreamedResponse
    {
        $disk = Storage::disk('local');

        abort_unless(filled($customer->signature_path) && $disk->exists($customer->signature_path), 404);

        return $disk->download(
            $customer->signature_path,
            "signature-{$customer->id}.png",
            [
                'Content-Type' => 'image/png',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline',
            ],
        );
    }
}
