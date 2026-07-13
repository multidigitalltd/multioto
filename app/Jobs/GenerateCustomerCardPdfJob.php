<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Mail\CustomerCardMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Support\Branding;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;

/**
 * Render the signed "customer card" PDF (details + drawn signature), store it
 * privately on the customer as the consent record, and email it to the customer
 * with a thank-you. Runs on the queue — never inside the signup request.
 *
 * Idempotent: re-running regenerates the same document; the mail goes out once
 * per generation. Skips silently if the customer has no signature yet.
 */
class GenerateCustomerCardPdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    private const PAYMENT_LABELS = [
        'credit_card' => 'כרטיס אשראי',
        'standing_order' => 'הוראת קבע בנקאית',
        'bank_transfer' => 'העברה בנקאית',
        'checks' => 'צ׳קים',
    ];

    public function __construct(public int $customerId) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);

        if (! $customer || blank($customer->signature_path)) {
            return;
        }

        $disk = Storage::disk('local');

        // The PDF is the signed consent record — never generate it without the
        // actual signature. If the file is missing (e.g. a worker that can't see
        // the storage yet), fail so the job retries instead of emailing a blank.
        if (! $disk->exists($customer->signature_path)) {
            throw new \RuntimeException("Signature file missing for customer {$customer->id}: {$customer->signature_path}");
        }

        $signature = 'data:image/png;base64,'.base64_encode($disk->get($customer->signature_path));

        $html = View::make('pdf.customer-card', [
            'customer' => $customer,
            'signature' => $signature,
            'logo' => Branding::logoDataUri(),
            'paymentMethod' => self::PAYMENT_LABELS[$customer->payment_method] ?? '—',
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->render();

        // mPDF (not dompdf) — it does proper RTL bidi so Hebrew renders correctly.
        $tmp = storage_path('app/mpdf-tmp');
        is_dir($tmp) || mkdir($tmp, 0775, true);

        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'directionality' => 'rtl', 'tempDir' => $tmp]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->WriteHTML($html);
        $pdf = $mpdf->Output('', 'S');

        // Store the signed card privately and record its path on the customer.
        $path = 'customer-cards/'.now()->format('Y/m').'/'.$customer->id.'-'.substr(md5($customer->signature_path), 0, 8).'.pdf';
        $disk->put($path, $pdf);
        $customer->update(['signed_pdf_path' => $path]);

        // Thank-you email with the signed card attached (skip if no address).
        if (filled($customer->email)) {
            Mail::to($customer->email)->send(new CustomerCardMail($customer->name, $pdf));
            NotificationLog::record('email', NotificationType::CustomerCard, $customer->email, 'כרטיס הלקוח החתום שלך', 'כרטיס לקוח חתום (PDF) נשלח כמצורף.', $customer->id);
        }
    }
}
