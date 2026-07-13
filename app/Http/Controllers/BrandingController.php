<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the business logo at a stable, public URL. Emails must reference a
 * hosted https image — mail clients (Gmail especially) block data: URIs — and
 * this route works regardless of whether the public storage symlink is served,
 * so the logo always resolves in customer inboxes.
 */
class BrandingController extends Controller
{
    public function logo(): Response|StreamedResponse
    {
        $file = Branding::logoFile();

        if ($file === null) {
            abort(404);
        }

        return response()->stream(function () use ($file) {
            echo $file['contents'];
        }, 200, [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
