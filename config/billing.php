<?php

/**
 * Billing + dunning configuration.
 *
 * All amounts are integer agorot. All dunning timings live here — jobs must
 * never hardcode stage days or retry offsets.
 */
return [

    // Israeli VAT rate applied on top of plan price for non-exempt customers.
    'vat_rate' => env('BILLING_VAT_RATE', 0.18),

    'currency' => 'ILS',

    // How long a signed card-update link (embedded in dunning messages) stays
    // valid. Short-lived so a forwarded/leaked message can't be reused forever.
    'card_update_link_ttl_hours' => env('CARD_UPDATE_LINK_TTL_HOURS', 72),

    /*
     | Dunning state machine. Stage 0 means "healthy". A failed charge moves the
     | subscription to stage 1 immediately; each subsequent stage is entered when
     | its retry (scheduled retry_in_days after the previous stage) fails too.
     |
     | notify: message template keys sent on entering the stage (WhatsApp + email).
     | retry_in_days: days until the next automatic charge retry, null = no retry.
     | suspend: suspend the site + subscription when this stage's charge fails.
     */
    'dunning' => [
        'stages' => [
            1 => ['template' => 'payment_failed', 'retry_in_days' => 2, 'suspend' => false],
            2 => ['template' => 'payment_failed_reminder', 'retry_in_days' => 3, 'suspend' => false],
            3 => ['template' => 'suspension_warning', 'retry_in_days' => 3, 'suspend' => false],
            4 => ['template' => 'site_suspended', 'retry_in_days' => null, 'suspend' => true],
        ],
        'channels' => ['whatsapp', 'email'],
    ],

    'cardcom' => [
        'terminal_number' => env('CARDCOM_TERMINAL_NUMBER'),
        'api_name' => env('CARDCOM_API_NAME'),
        'api_password' => env('CARDCOM_API_PASSWORD'),
        'base_url' => env('CARDCOM_BASE_URL', 'https://secure.cardcom.solutions/api/v11'),
        // Shared secret we embed in Low Profile return/webhook URLs for origin verification.
        'webhook_secret' => env('CARDCOM_WEBHOOK_SECRET'),
    ],

    'linet' => [
        // Linet authenticates with three values from the account (see the
        // Linet API settings screen): Login ID, Key, and Company ID.
        'login_id' => env('LINET_LOGIN_ID'),
        'key' => env('LINET_KEY'),
        'company_id' => env('LINET_COMPANY_ID'),
        'base_url' => env('LINET_BASE_URL', 'https://app.linet.org.il/api/v1'),
        // Send the invoice to the customer by email from Linet's side.
        'email_document' => env('LINET_EMAIL_DOCUMENT', true),
    ],

    'waha' => [
        'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
        'api_key' => env('WAHA_API_KEY'),
        'session' => env('WAHA_SESSION', 'default'),
        'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
        // Minimum seconds between outbound messages in bulk sends (block-risk mitigation).
        'broadcast_throttle_seconds' => env('WAHA_BROADCAST_THROTTLE', 30),
    ],

    'email' => [
        // Shared secret the inbound-parse provider includes on its webhook URL.
        'webhook_secret' => env('EMAIL_WEBHOOK_SECRET'),
        // Address customers reply to; agent email replies are sent from here.
        'support_address' => env('SUPPORT_EMAIL', 'support@multi.digital'),
    ],

    'hosting' => [
        // Driver behind HostingClient: 'flywp' (real) or 'log' (records intent only).
        'driver' => env('HOSTING_DRIVER', 'log'),

        'flywp' => [
            'base_url' => env('FLYWP_BASE_URL', 'https://app.flywp.com/api/v1'),
            'api_token' => env('FLYWP_API_TOKEN'),
            'server_id' => env('FLYWP_SERVER_ID'),
            // Suspend/restore via maintenance mode. {server}/{site} are substituted;
            // site is the FlyWP site id stored on sites.hosting_ref.
            'maintenance_path' => env('FLYWP_MAINTENANCE_PATH', 'servers/{server}/sites/{site}/maintenance'),
        ],
    ],

    'ai' => [
        // Optional Tier-1 AI (Stage 5). When api_key is empty the layer is a
        // no-op — tickets are still handled manually, nothing breaks.
        'enabled' => env('AI_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('AI_MODEL', 'claude-opus-4-8'),
        'effort' => env('AI_EFFORT', 'low'),
    ],

    'monitoring' => [
        'interval_minutes' => env('MONITOR_INTERVAL_MINUTES', 5),
        'timeout_seconds' => env('MONITOR_TIMEOUT_SECONDS', 10),
        // Consecutive failed checks before an incident is opened.
        'failures_to_incident' => env('MONITOR_FAILURES_TO_INCIDENT', 2),
    ],

    'broadcasts' => [
        'email_chunk_size' => env('BROADCAST_EMAIL_CHUNK', 50),
    ],
];
