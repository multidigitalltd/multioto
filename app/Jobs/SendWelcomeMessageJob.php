<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Personal welcome for a newly signed-up customer — email plus WhatsApp when
 * a phone exists. Dispatched only from explicit signup/onboarding flows (never
 * from bulk import, which would spam existing customers). Template-driven and
 * best-effort per channel: one channel failing never blocks the other.
 */
class SendWelcomeMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $customerId) {}

    public function handle(TemplateEngine $templates, WahaClient $waha): void
    {
        $customer = Customer::find($this->customerId);

        if (! $customer) {
            return;
        }

        $data = [
            'customer_name' => $customer->contact_name ?: $customer->name,
            'business_name' => config('mail.from.name') ?: config('app.name'),
        ];

        if (filled($customer->email) && ($email = $templates->render('customer.welcome', 'email', $data))) {
            Mail::to($customer->email)->send(new NotificationMail($email['subject'] ?? 'ברוכים הבאים', $email['body']));
            NotificationLog::record('email', NotificationType::Welcome, $customer->email, $email['subject'] ?? null, $email['body'], $customer->id);
        }

        if (filled($customer->phone) && ($wa = $templates->render('customer.welcome', 'whatsapp', $data))) {
            $recipient = $customer->whatsapp_jid ?: $customer->phone;
            try {
                $waha->sendMessage($recipient, $wa['body']);
                NotificationLog::record('whatsapp', NotificationType::Welcome, $recipient, null, $wa['body'], $customer->id);
            } catch (\Throwable $e) {
                // WhatsApp being down must not fail the welcome (email was sent).
                Log::warning('Welcome WhatsApp send failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
                NotificationLog::record('whatsapp', NotificationType::Welcome, $recipient, null, $wa['body'], $customer->id, 'failed', $e->getMessage());
            }
        }
    }
}
