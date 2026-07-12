<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a customer's signed card PDF (details + signature) to a signed-in
 * team member. Behind panel auth — never public. Inline with `nosniff`.
 */
class CustomerCardPdfController extends Controller
{
    public function __invoke(Customer $customer): StreamedResponse
    {
        $disk = Storage::disk('local');

        abort_unless(filled($customer->signed_pdf_path) && $disk->exists($customer->signed_pdf_path), 404);

        return $disk->download(
            $customer->signed_pdf_path,
            "customer-card-{$customer->id}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline',
            ],
        );
    }
}
