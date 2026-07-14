<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One-time login code (2FA)
    |--------------------------------------------------------------------------
    |
    | Team members can be required to confirm a short numeric code — delivered
    | by email or WhatsApp — after entering their password. These settings tune
    | the code itself; whether a given user is challenged is a per-user flag.
    |
    */

    // Number of digits in the code (kept short enough to type from a phone).
    'code_length' => (int) env('TWO_FACTOR_CODE_LENGTH', 6),

    // How long a code stays valid, in seconds.
    'ttl_seconds' => (int) env('TWO_FACTOR_TTL_SECONDS', 300),

    // Minimum gap between "resend" requests, in seconds (anti-flood).
    'resend_throttle_seconds' => (int) env('TWO_FACTOR_RESEND_THROTTLE_SECONDS', 30),

    // Wrong-code attempts allowed before the current code is invalidated and a
    // fresh one must be requested.
    'max_attempts' => (int) env('TWO_FACTOR_MAX_ATTEMPTS', 5),

];
