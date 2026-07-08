<?php

namespace App\Services\Health;

/**
 * Outcome of an integration connection test. `configured` is false when the
 * credentials aren't set yet (so the UI shows "not configured" rather than a
 * scary failure).
 */
class ConnectionResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public bool $configured = true,
    ) {}

    public static function ok(string $message): self
    {
        return new self(true, $message, true);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message, true);
    }

    public static function notConfigured(string $message = 'המפתחות לא הוגדרו עדיין'): self
    {
        return new self(false, $message, false);
    }

    /** Status keyword for the UI: 'ok' | 'fail' | 'unconfigured'. */
    public function state(): string
    {
        if (! $this->configured) {
            return 'unconfigured';
        }

        return $this->ok ? 'ok' : 'fail';
    }
}
