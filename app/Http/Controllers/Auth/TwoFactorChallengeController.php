<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorChannel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one-time-code screen shown between password login and the panel for
 * members with 2FA enabled. Landing here sends a code (unless one is still
 * live); submitting the right code marks the session confirmed so the panel
 * middleware lets the member through.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorCode $codes) {}

    /** Show the challenge, sending a first code when none is pending. */
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->requiresTwoFactor() || session()->get('two_factor.confirmed', false)) {
            return redirect()->intended(route('filament.admin.pages.dashboard'));
        }

        // Send a code on first arrival — i.e. when nothing valid is outstanding.
        if ($user->two_factor_code === null || $user->two_factor_expires_at?->isPast()) {
            $this->codes->send($user->fresh());
        }

        return view('auth.two-factor', [
            'channel' => $user->two_factor_channel ?? TwoFactorChannel::Email,
            'destination' => $this->maskedDestination($user),
        ]);
    }

    /** Verify a submitted code and, on success, confirm the session. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ], [], ['code' => 'קוד']);

        /** @var User $user */
        $user = $request->user();

        if (! $this->codes->verify($user->fresh(), trim($data['code']))) {
            throw ValidationException::withMessages([
                'code' => 'הקוד שגוי או שפג תוקפו. אפשר לבקש קוד חדש.',
            ]);
        }

        // Rotate the session id on privilege elevation, then mark it confirmed.
        $request->session()->regenerate();
        session()->put('two_factor.confirmed', true);

        return redirect()->intended(route('filament.admin.pages.dashboard'));
    }

    /** Send a fresh code, respecting the resend cool-down. */
    public function resend(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user()->fresh();

        if (! $user->requiresTwoFactor()) {
            return redirect()->route('filament.admin.pages.dashboard');
        }

        if (! $this->codes->canResend($user)) {
            return back()->with('status', 'כבר נשלח קוד — נסו שוב בעוד רגע.');
        }

        return back()->with(
            'status',
            $this->codes->send($user)
                ? 'קוד חדש נשלח.'
                : 'לא הצלחנו לשלוח את הקוד — פנו למנהל המערכת.',
        );
    }

    /** A privacy-preserving hint of where the code was sent. */
    private function maskedDestination(User $user): string
    {
        if (($user->two_factor_channel ?? TwoFactorChannel::Email) === TwoFactorChannel::Whatsapp) {
            $phone = preg_replace('/\D/', '', (string) $user->phone);

            return $phone === '' ? '' : '••••'.Str::substr($phone, -4);
        }

        [$name, $domain] = array_pad(explode('@', (string) $user->email, 2), 2, '');
        $head = Str::substr($name, 0, 1);

        return $domain === '' ? $user->email : "{$head}•••@{$domain}";
    }
}
