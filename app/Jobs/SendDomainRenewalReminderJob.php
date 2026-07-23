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

    /**
     * @param  array<int, string>|null  $channels  Which channels to send on
     *                                             ('email', 'whatsapp'); null = both.
     */
    public function __construct(public int $siteId, public ?array $channels = null) {}

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

        // null = both channels (default); otherwise only the operator's picks.
        $wantsEmail = $this->channels === null || in_array('email', $this->channels, true);
        $wantsWhatsapp = $this->channels === null || in_array('whatsapp', $this->channels, true);

        // Per-channel best-effort: each channel is independent, so an email
        // transport outage never blocks the WhatsApp reminder (and vice versa).
        if ($wantsEmail && filled($customer->email) && ($email = $templates->render('domain.renewal', 'email', $data))) {
            try {
                Mail::to($customer->email)->send(new NotificationMail($email['subject'] ?? 'חידוש דומיין', $email['body']));
                NotificationLog::record('email', NotificationType::DomainRenewal, $customer->email, $email['subject'] ?? null, $email['body'], $customer->id);
            } catch (\Throwable $e) {
                Log::warning('Domain renewal email send failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
                NotificationLog::record('email', NotificationType::DomainRenewal, $customer->email, $email['subject'] ?? null, $email['body'], $customer->id, 'failed', $e->getMessage());
            }
        }

        // WhatsApp goes to the stored JID or, failing that, the phone — the same
        // resolution every other customer-facing flow uses.
        $recipient = $customer->whatsappRecipient();
        if ($wantsWhatsapp && filled($recipient) && ($wa = $templates->render('domain.renewal', 'whatsapp', $data))) {
            try {
                $waha->sendMessage($recipient, $wa['body']);
                NotificationLog::record('whatsapp', NotificationType::DomainRenewal, $recipient, null, $wa['body'], $customer->id);
            } catch (\Throwable $e) {
                Log::warning('Domain renewal WhatsApp send failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
                NotificationLog::record('whatsapp', NotificationType::DomainRenewal, $recipient, null, $wa['body'], $customer->id, 'failed', $e->getMessage());
            }
        }
    }
}
