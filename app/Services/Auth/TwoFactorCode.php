<?php

namespace App\Services\Auth;

use App\Enums\TwoFactorChannel;
use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Issues and verifies the short one-time code a team member enters after their
 * password when 2FA is enabled. The code is stored only as a hash with a short
 * expiry, delivered over the member's chosen channel (email or WhatsApp), and
 * consumed on the first correct match.
 */
class TwoFactorCode
{
    public function __construct(private WahaClient $waha) {}

    /**
     * Generate a fresh code, persist its hash + expiry on the user and deliver
     * it over their channel. Returns false when delivery could not even be
     * attempted (e.g. WhatsApp chosen but no phone on file).
     */
    public function send(User $user): bool
    {
        $channel = $user->two_factor_channel ?? TwoFactorChannel::Email;

        if ($channel === TwoFactorChannel::Whatsapp && blank($user->phone)) {
            return false;
        }

        $code = $this->generateCode();

        $user->forceFill([
            'two_factor_code' => Hash::make($code),
            'two_factor_expires_at' => now()->addSeconds((int) config('twofactor.ttl_seconds', 300)),
            'two_factor_last_sent_at' => now(),
            'two_factor_attempts' => 0,
        ])->save();

        return $this->deliver($user, $channel, $code);
    }

    /**
     * Whether the user may request another code right now — a fixed cool-down
     * keeps the resend button from flooding email/WhatsApp.
     */
    public function canResend(User $user): bool
    {
        $throttle = (int) config('twofactor.resend_throttle_seconds', 30);

        return $user->two_factor_last_sent_at === null
            || $user->two_factor_last_sent_at->addSeconds($throttle)->isPast();
    }

    /**
     * Verify a submitted code. A correct code is consumed (cleared) so it can
     * never be replayed; too many wrong tries invalidate the current code and
     * force a fresh request.
     */
    public function verify(User $user, string $code): bool
    {
        if (blank($user->two_factor_code) || $user->two_factor_expires_at === null) {
            return false;
        }

        if ($user->two_factor_expires_at->isPast()) {
            $this->clear($user);

            return false;
        }

        if (! Hash::check($code, $user->two_factor_code)) {
            $user->increment('two_factor_attempts');

            if ($user->two_factor_attempts >= (int) config('twofactor.max_attempts', 5)) {
                $this->clear($user);
            }

            return false;
        }

        $this->clear($user);

        return true;
    }

    /** Wipe any pending challenge from the user. */
    public function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_attempts' => 0,
        ])->save();
    }

    /** A zero-padded numeric code of the configured length. */
    private function generateCode(): string
    {
        $length = max(4, (int) config('twofactor.code_length', 6));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /** Deliver the code over the chosen channel; best-effort, logged on failure. */
    private function deliver(User $user, TwoFactorChannel $channel, string $code): bool
    {
        $minutes = max(1, (int) round((int) config('twofactor.ttl_seconds', 300) / 60));
        $subject = 'קוד כניסה — מולטי דיגיטל';
        $body = "קוד הכניסה החד-פעמי שלך הוא: {$code}\n\nהקוד תקף ל-{$minutes} דקות. אם לא ניסית להתחבר, אפשר להתעלם מהודעה זו.";

        try {
            if ($channel === TwoFactorChannel::Whatsapp) {
                $this->waha->sendMessage($this->waha->normalizeChatId((string) $user->phone), $body);

                return true;
            }

            Mail::to($user->email)->send(new NotificationMail($subject, $body));

            return true;
        } catch (\Throwable $e) {
            Log::warning('TwoFactorCode: delivery failed', [
                'user' => $user->id,
                'channel' => $channel->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
