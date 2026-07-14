<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The customer-facing monthly monitoring report — a designed, RTL summary of
 * each of the customer's monitored sites over the reporting window.
 */
class MonitoringReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public Customer $customer,
        public array $report,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'דוח ניטור חודשי — האתרים שלך');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monitoring-report');
    }
}
