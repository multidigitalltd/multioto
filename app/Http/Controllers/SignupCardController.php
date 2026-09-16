<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\PendingSignup;
use App\Services\Cardcom\CardcomClient;
use App\Services\Notifications\TeamNotifier;
use App\Services\Signup\CompleteSignup;
use App\Support\CardcomWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The card step of the public signup — and the gate the customer record is on
 * the other side of.
 *
 * Nothing in `customers` is written until Cardcom hands back a token. A visitor
 * who closes this page leaves a pending signup that expires on its own, not a
 * customer we have no way to collect from.
 *
 * Cardcom announces a finished session twice: a webhook to the server and a
 * redirect to this browser, in no guaranteed order and with either one liable
 * to be lost. Both complete the signup, and completing is idempotent — so the
 * customer is created by whichever arrives, exactly once.
 */
class SignupCardController extends Controller
{
    /** The hosted card page, embedded, for a signup still waiting on a card. */
    public function show(string $pending, CardcomClient $cardcom): View
    {
        $signup = $this->find($pending);

        if ($signup === null) {
            return view('signup.expired');
        }

        if ($signup->completed_at !== null) {
            return view('signup.done', ['pending' => $signup]);
        }

        $context = $this->context($signup);

        try {
            $lowProfile = $cardcom->createSignupTokenLowProfile(
                $signup,
                route('signup.done', ['pending' => $signup->token]),
                URL::temporarySignedRoute('signup.card.failed', now()->addDay(), ['pending' => $signup->token]),
                CardcomWebhook::url(),
            );
        } catch (\Throwable $e) {
            Log::error('signup: Cardcom token page creation threw', [
                'pending_signup_id' => $signup->id,
                'error' => Str::limit($e->getMessage(), 300),
            ]);

            return $this->unavailable($signup);
        }

        $cardUrl = (string) ($lowProfile['url'] ?? '');

        // Only ever frame a real Cardcom https page. An empty/invalid URL means
        // Cardcom rejected the request (logged in the client).
        if (! Str::startsWith($cardUrl, 'https://')) {
            return $this->unavailable($signup);
        }

        if (! empty($lowProfile['low_profile_id'])) {
            $signup->forceFill(['cardcom_lp_id' => $lowProfile['low_profile_id']])->save();
        }

        return view('signup.card', [...$context, 'cardUrl' => $cardUrl, 'pending' => $signup]);
    }

    /**
     * Cardcom sent the customer back saying the session finished.
     *
     * The redirect is not taken as proof. Cardcom is asked what actually
     * happened to this session, and the customer is created only if that answer
     * carries a token — a browser can arrive here by any route, and a customer
     * opened on the strength of a URL is a customer with no card.
     */
    public function done(string $pending, CardcomClient $cardcom, CompleteSignup $complete): View
    {
        $signup = PendingSignup::query()->where('token', $pending)->first();

        if ($signup === null) {
            return view('signup.expired');
        }

        if ($signup->completed_at !== null) {
            return view('signup.done', ['pending' => $signup]);
        }

        if (filled($signup->cardcom_lp_id)) {
            try {
                $result = $cardcom->getLpResult((string) $signup->cardcom_lp_id);

                if ($complete->withCard($signup, $result) !== null) {
                    return view('signup.done', ['pending' => $signup->fresh()]);
                }
            } catch (\Throwable $e) {
                Log::warning('signup: could not read the Cardcom result on return', [
                    'pending_signup_id' => $signup->id,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);
            }
        }

        // No token yet. The webhook may still be in flight, so this is not a
        // failure to announce — the page says the card is being confirmed and
        // offers the way back in.
        return view('signup.pending-card', ['pending' => $signup]);
    }

    /**
     * A card Cardcom refused.
     *
     * Cardcom sends no webhook for a declined deal, so this redirect is the
     * only moment anybody learns of it — and here it matters more than it used
     * to, because a refused card means no customer was opened at all.
     */
    public function failed(string $pending, CardcomClient $cardcom): View
    {
        $signup = PendingSignup::query()->where('token', $pending)->first();

        if ($signup === null) {
            return view('signup.expired');
        }

        $result = [];

        if (filled($signup->cardcom_lp_id)) {
            try {
                $result = $cardcom->getLpResult((string) $signup->cardcom_lp_id);
            } catch (\Throwable $e) {
                Log::warning('signup: could not read the Cardcom failure result', [
                    'pending_signup_id' => $signup->id,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);
            }
        }

        $reason = trim((string) (
            data_get($result, 'TranzactionInfo.Description')
                ?: data_get($result, 'Description')
                ?: ''
        ));

        $this->announceFailure($signup, $reason);

        return view('signup.card-failed', [
            'pending' => $signup,
            // Cardcom writes these for the card holder and they say the one
            // thing that helps ("call your card company").
            'reason' => $reason,
        ]);
    }

    /**
     * Cardcom could not give us a card page.
     *
     * The signup is kept and the customer is told plainly, because the
     * alternative — opening the customer anyway — is the thing this flow exists
     * to prevent. The team is told too: a prospect who reached this page wanted
     * to join and could not, and nobody else will ever notice.
     */
    private function unavailable(PendingSignup $signup): View
    {
        $this->alert(
            $signup,
            '⚠️ הרשמה נעצרה — לא ניתן לפתוח עמוד כרטיס',
            'לא הצלחנו לפתוח עמוד סליקה עבור הנרשם. ההרשמה שמורה ולא נפתח לקוח.',
        );

        return view('signup.card-unavailable', ['pending' => $signup]);
    }

    /** Tell the team a card was refused — once per session, not per reload. */
    private function announceFailure(PendingSignup $signup, string $reason): void
    {
        $this->alert(
            $signup,
            '💳 כרטיס נדחה בהרשמה — '.$signup->name,
            $reason !== '' ? "סיבה מקארדקום: {$reason}" : 'קארדקום לא החזירה סיבה.',
        );
    }

    /** One alert per pending signup and session, however often the page reloads. */
    private function alert(PendingSignup $signup, string $title, string $line): void
    {
        $key = 'signup-alert:'.$signup->id.':'.($signup->cardcom_lp_id ?: 'none').':'.md5($title);

        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        try {
            app(TeamNotifier::class)->alert($title, implode("\n", [
                "נרשם: {$signup->name}",
                "טלפון: {$signup->phone}",
                "אימייל: {$signup->email}",
                $line,
                'לא נפתח כרטיס לקוח — יש ליצור קשר.',
            ]));
        } catch (\Throwable $e) {
            Log::warning('signup: alert could not be sent', ['error' => $e->getMessage()]);
        }
    }

    /** The open pending signup behind this token, or null. */
    private function find(string $token): ?PendingSignup
    {
        $signup = PendingSignup::query()->where('token', $token)->first();

        if ($signup === null) {
            return null;
        }

        return $signup->completed_at !== null || $signup->isOpen() ? $signup : null;
    }

    /**
     * What this particular signup needs told on the card page.
     *
     * @return array<string, mixed>
     */
    private function context(PendingSignup $signup): array
    {
        $method = (string) $signup->payment_method;

        if (! PaymentMethod::isManualValue($method)) {
            return ['securityCard' => false, 'methodLabel' => null, 'paymentInstructions' => null, 'fallbackDays' => 0];
        }

        return [
            'securityCard' => true,
            'methodLabel' => PaymentMethod::tryFrom($method)?->getLabel() ?? '',
            'paymentInstructions' => trim((string) config('billing.signup.instructions.'.$method)),
            // A signup has no subscription yet, so the arrangement they are
            // agreeing to right now is the standing one.
            'fallbackDays' => (int) config('billing.card_fallback_days', 0),
        ];
    }
}
