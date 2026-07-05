<?php

namespace App\Http\Controllers;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Http\Requests\FormTicketRequest;
use App\Services\Support\TicketIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Public "contact us" support form — the third ticket intake channel alongside
 * WhatsApp and email. Protected by CSRF, rate limiting and a honeypot.
 */
class SupportFormController extends Controller
{
    public function show(): View
    {
        return view('support.form');
    }

    public function store(FormTicketRequest $request, TicketIntake $intake): RedirectResponse
    {
        $data = $request->validated();

        $customer = $intake->matchCustomer(
            email: strtolower($data['email']),
            phone: $data['phone'] ?? null,
        );

        $intake->recordInbound(
            channel: TicketChannel::Form,
            messageChannel: MessageChannel::Email,
            customer: $customer,
            body: $data['message'],
            subject: $data['subject'],
            // Each form submission opens its own ticket (no thread reference).
        );

        return redirect()
            ->route('support.form')
            ->with('status', 'הפנייה נשלחה בהצלחה. נחזור אליך בהקדם.');
    }
}
