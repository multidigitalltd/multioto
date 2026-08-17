<?php

namespace App\Services\Licensing;

use App\Mail\LicenseKeyMail;
use App\Models\License;
use App\Models\PluginProduct;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Mail;

/**
 * Issuing a licence, and getting the key to the person who bought it.
 *
 * The key exists for one instant: it is generated, its HMAC is stored, and the
 * plaintext is handed to this class to put in an email. After that nothing in
 * the system can produce it again — not a screen, not a query, not us. That is
 * the point, and it makes delivery of this one message matter more than most:
 * a key that failed to send is a key that no longer exists anywhere.
 *
 * So sending is reported rather than assumed. Every path here returns whether
 * the mail actually went out, and the caller says so on screen.
 */
class LicenseIssuer
{
    /**
     * Create a licence and email the key.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: License, 1: string, 2: bool} the licence, the key, and whether it was emailed
     */
    public function issue(array $attributes): array
    {
        // Remembered at the moment of sale, because it cannot be reconstructed
        // afterwards: once a newer build is published, nothing in the data says
        // which one this customer actually received. Without it, a licence sold
        // without updates has no re-download that is both honest and useful.
        $attributes['delivered_release_id'] ??= PluginProduct::find($attributes['plugin_product_id'] ?? null)
            ?->currentRelease()?->id;

        [$license, $key] = License::issue($attributes);

        $sent = $this->send($license, $key, replacement: false);

        SystemLog::record('info', 'licensing',
            "הונפק רישיון {$license->key_prefix}… ל".($license->product?->name ?? 'תוסף')
                .($sent ? ' ונשלח ל'.$license->email : ' (לא נשלח במייל — אין כתובת)'),
            ['license_id' => $license->id]);

        return [$license, $key, $sent];
    }

    /**
     * Replace the key on an existing licence and email the new one.
     *
     * The old key stops working the instant this runs. That is not a side
     * effect to be discovered later — it is the whole operation, and the screen
     * offering it says so before it is pressed.
     */
    public function reissue(License $license): bool
    {
        $key = $license->regenerateKey();

        $sent = $this->send($license, $key, replacement: true);

        SystemLog::record('warning', 'licensing',
            "הונפק מפתח חדש לרישיון {$license->key_prefix}… — המפתח הקודם בוטל"
                .($sent ? ' והחדש נשלח ל'.$license->email : ' ולא נשלח במייל (אין כתובת)'),
            ['license_id' => $license->id]);

        return $sent;
    }

    /**
     * Put the key in the customer's inbox.
     *
     * A licence without an email address is a real case — a key handed over in
     * person, or bought by somebody who is not a customer in the system. It is
     * reported as "not sent" rather than treated as a failure, because the
     * licence itself was issued correctly.
     */
    private function send(License $license, string $key, bool $replacement): bool
    {
        $address = trim((string) ($license->email ?: $license->customer?->email));

        if ($address === '') {
            return false;
        }

        Mail::to($address)->send(new LicenseKeyMail($license->fresh('product'), $key, $replacement));

        return true;
    }
}
