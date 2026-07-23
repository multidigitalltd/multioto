<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\NotificationLog;
use App\Models\Site;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Remind a customer that their domain registration is about to expire — email
 * plus WhatsApp when a phone exists. Dispatched on demand from the site page
 * ("שלח תזכורת חידוש ללקוח") for the case where the CUSTOMER, not us, renews the
 * domain. Template-driven and best-effort per channel.
 */
class SendDomainRenewalReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $siteId) {}

    public function handle(TemplateEngine $templates, WahaClient $waha): void
    {
        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->customer || $site->domain_expiry_at === null) {
            return;
        }

        $customer = $site->customer;
        $daysLeft = (int) ceil(now()->startOfDay()->diffInDays($site->domain_expiry_at, false));

        $data = [
            'customer_name' => $customer->contact_name ?: $customer->name,
            'business_name' => config('mail.from.name') ?: config('app.name'),
            'domain' => $site->domain,
            'expiry_date' => $site->domain_expiry_at->format('d/m/Y'),
            'days_left' => max(0, $daysLeft),
        ];

        if (filled($customer->email) && ($email = $templates->render('domain.renewal', 'email', $data))) {
            Mail::to($customer->email)->send(new NotificationMail($email['subject'] ?? 'חידוש דומיין', $email['body']));
            NotificationLog::record('email', NotificationType::DomainRenewal, $customer->email, $email['subject'] ?? null, $email['body'], $customer->id);
        }

        if (filled($customer->phone) && ($wa = $templates->render('domain.renewal', 'whatsapp', $data))) {
            $recipient = $customer->whatsappRecipient();
            try {
                $waha->sendMessage($recipient, $wa['body']);
                NotificationLog::record('whatsapp', NotificationType::DomainRenewal, $recipient, null, $wa['body'], $customer->id);
            } catch (\Throwable $e) {
                // WhatsApp being down must not fail the reminder (email was sent).
                Log::warning('Domain renewal WhatsApp send failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
                NotificationLog::record('whatsapp', NotificationType::DomainRenewal, $recipient, null, $wa['body'], $customer->id, 'failed', $e->getMessage());
            }
        }
    }
}
