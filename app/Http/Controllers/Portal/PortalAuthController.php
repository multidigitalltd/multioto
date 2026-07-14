<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Sign-in for the customer self-service portal. There are no customer
 * passwords: a customer enters the email on their record and receives a
 * short-lived signed magic link (by email, and WhatsApp when we have a number).
 * Following the link establishes a session-scoped portal login.
 */
class PortalAuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        // Already signed in — go straight to the dashboard.
        if (session()->has('portal.customer_id') && Customer::whereKey(session('portal.customer_id'))->exists()) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.login');
    }

    /**
     * Mail (and WhatsApp) a magic link to the address on file. The response is
     * identical whether or not the email matched an account, so the form can't
     * be used to discover which addresses are customers.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [], ['email' => 'אימייל']);

        $customer = Customer::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();

        if ($customer !== null) {
            $this->deliverLink($customer);
        }

        return back()->with('status', 'אם הכתובת רשומה אצלנו, שלחנו אליה קישור כניסה. הקישור תקף לזמן מוגבל.');
    }

    /**
     * Consume a valid signed link: bind the customer to the session and forward
     * to the dashboard. The 'signed' middleware guarantees the link is ours and
     * unexpired, so the customer id here can be trusted.
     */
    public function authenticate(Customer $customer, Request $request): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put('portal.customer_id', $customer->id);

        return redirect()->route('portal.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('portal.customer_id');

        return redirect()->route('portal.login')->with('status', 'התנתקת מהאזור האישי.');
    }

    /** Build the signed link and push it over the customer's channels. */
    private function deliverLink(Customer $customer): void
    {
        $link = URL::temporarySignedRoute(
            'portal.auth',
            now()->addMinutes((int) config('portal.login_link_ttl_minutes', 30)),
            ['customer' => $customer->id],
        );

        $subject = 'כניסה לאזור האישי — מולטי דיגיטל';
        $body = "שלום {$customer->name},\n\nלכניסה לאזור האישי שלך (חשבוניות, פניות ועדכון אמצעי תשלום) לחצו על הקישור:\n{$link}\n\nאם לא ביקשתם להיכנס, אפשר להתעלם מהודעה זו.";

        try {
            if (filled($customer->email)) {
                Mail::to($customer->email)->send(new NotificationMail($subject, $body));
            }
        } catch (\Throwable $e) {
            Log::warning('Portal: sign-in email failed', ['customer' => $customer->id, 'error' => $e->getMessage()]);
        }

        if (($recipient = $customer->whatsappRecipient()) !== null) {
            try {
                app(WahaClient::class)->sendMessage(
                    app(WahaClient::class)->normalizeChatId($recipient),
                    $body,
                );
            } catch (\Throwable $e) {
                Log::warning('Portal: sign-in WhatsApp failed', ['customer' => $customer->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
