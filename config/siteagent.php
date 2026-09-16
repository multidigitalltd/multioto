<?php

return [

    /*
    |--------------------------------------------------------------------------
    | סוכן האתר — the product a site owner subscribes to
    |--------------------------------------------------------------------------
    |
    | A dedicated WhatsApp number a customer writes to in order to run their own
    | site: change text, swap a picture, update a price. They approve their own
    | changes in the chat, and they pay a monthly subscription for it.
    |
    | Kept apart from config/agent.php on purpose. That file governs OUR agent —
    | the one the team drives, behind the team's approval gate and the team's
    | kill-switch. This one is a product sold to a customer, and conflating the
    | two would mean a switch flipped for one silently changing the other.
    |
    */

    // Master switch for the whole product. Off means the number answers that
    // the service is unavailable rather than pretending to work.
    'enabled' => (bool) env('SITE_AGENT_ENABLED', false),

    /*
    | The official WhatsApp Business Cloud API (Meta), NOT the WAHA bridge the
    | support inbox uses. A product a customer pays for needs a number that
    | cannot be taken down by a phone losing its session.
    */
    'whatsapp' => [
        // Graph API version and the number's own id, from the Meta app.
        'api_version' => env('SITE_AGENT_WA_API_VERSION', 'v21.0'),
        'phone_number_id' => env('SITE_AGENT_WA_PHONE_NUMBER_ID', ''),

        // Permanent access token of the system user that owns the number.
        'token' => env('SITE_AGENT_WA_TOKEN', ''),

        // The app secret, used to verify the X-Hub-Signature-256 on every
        // delivery. Meta signs its webhooks; a delivery we cannot verify is a
        // delivery from anybody.
        'app_secret' => env('SITE_AGENT_WA_APP_SECRET', ''),

        // The token Meta echoes back once, when the webhook is first verified.
        'verify_token' => env('SITE_AGENT_WA_VERIFY_TOKEN', ''),

        'timeout_seconds' => (int) env('SITE_AGENT_WA_TIMEOUT', 20),
    ],

    /*
    | How long an offer waits for a yes. Long enough to answer after a meeting,
    | short enough that a "כן" typed tomorrow cannot confirm something the
    | customer has long stopped thinking about.
    */
    'confirmation_minutes' => (int) env('SITE_AGENT_CONFIRMATION_MINUTES', 30),

    /*
    | How long "בטל" can still put a change back. The previous content is kept
    | on the request, so the undo restores what was actually live rather than
    | what we believed was.
    */
    'undo_minutes' => (int) env('SITE_AGENT_UNDO_MINUTES', 1440),

    /*
    | A number is bound to one customer's site before it can do anything. An
    | unknown number gets a polite "this number is not registered" — never a
    | guess at which site it might mean.
    |
    | verification_ttl_minutes: how long a binding code stays usable.
    */
    'binding' => [
        'verification_ttl_minutes' => (int) env('SITE_AGENT_BINDING_TTL_MINUTES', 30),
    ],

];
