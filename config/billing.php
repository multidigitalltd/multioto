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
        // Fixed API endpoint — hardcoded so a stale .env can't point it wrong.
        'base_url' => 'https://secure.cardcom.solutions/api/v11',
        // Shared secret we embed in Low Profile return/webhook URLs for origin verification.
        'webhook_secret' => env('CARDCOM_WEBHOOK_SECRET'),

        // Some Cardcom terminals REQUIRE a Document object on every request and
        // reject one without it (error 5046 "No InvoiceHead data was send").
        // We therefore attach a document. Its type controls what Cardcom emits:
        //  - 'Order' (default) — a NON-fiscal order document. Satisfies the
        //    terminal without issuing a tax invoice, so Linet stays the invoicer.
        //  - 'Auto' / 'TaxInvoiceAndReceipt' — let Cardcom issue the fiscal
        //    invoice itself (then you'd disable Linet issuance to avoid duplicates).
        //  - '' (empty) — send NO Document at all (only for terminals that don't
        //    require one).
        'document_type' => env('CARDCOM_DOCUMENT_TYPE', 'Order'),
    ],

    'linet' => [
        // Linet authenticates by sending these three values in the request BODY
        // of every call: login_id (API ID), login_hash (API Key), login_company
        // (Company ID). See https://www.linet.org.il/linet-api-documentation/.
        'login_id' => env('LINET_LOGIN_ID'),
        'key' => env('LINET_KEY'),
        'company_id' => env('LINET_COMPANY_ID'),
        // Fixed API endpoint — hardcoded so a stale .env can't point it wrong.
        'base_url' => 'https://app.linet.org.il/api',

        // Send the created document to the customer by email from Linet's side.
        'email_document' => env('LINET_EMAIL_DOCUMENT', true),

        // Account-specific codes from YOUR Linet setup — verify these against
        // your account before issuing real documents:
        //  - doctype: the document-type code for a tax-invoice/receipt (חשבונית מס/קבלה)
        //  - vat_cat_taxable / vat_cat_exempt: VAT category ids (taxable vs exempt)
        //  - payment_type: docCheq payment method (e.g. credit card)
        'doctype' => env('LINET_DOCTYPE'),
        // Linet vat_cat_id values. Linet's own plugin hardcodes 1 = taxable,
        // 2 = exempt/abroad — verified against the live API (other codes are
        // rejected with "Income VAT account must match VAT percent").
        'vat_cat_taxable' => env('LINET_VAT_CAT_TAXABLE', 1),
        'vat_cat_exempt' => env('LINET_VAT_CAT_EXEMPT', 2),

        // Income account for EXEMPT document lines. Linet requires a no-VAT
        // income account on a 0%-VAT line ("No VAT income account must be
        // selected") — taxable lines use the item's default income account.
        // 102 is the exempt-income account in Linet's standard chart.
        'income_account_exempt' => env('LINET_INCOME_ACCOUNT_EXEMPT', 102),
        'payment_type' => env('LINET_PAYMENT_TYPE', 3),
        'create_doc_path' => env('LINET_CREATE_DOC_PATH', '/create/doc'),
        // Linet item id used for our free-text service lines (every document line
        // must reference an item). Linet's own plugin defaults the general item
        // to "1"; override if your account uses a different general item.
        'general_item_id' => env('LINET_GENERAL_ITEM_ID', '1'),
    ],

    'waha' => [
        'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
        'api_key' => env('WAHA_API_KEY'),
        'session' => env('WAHA_SESSION', 'default'),
        'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
        // Country code prepended to local numbers (leading 0 → this) when building
        // a WhatsApp chat id. Israel = 972.
        'default_country_code' => env('WAHA_DEFAULT_COUNTRY_CODE', '972'),

        // The business owner's WhatsApp — receives approval requests from the
        // automation gate and replies "אשר <id>" / "דחה <id>".
        'owner_number' => env('WAHA_OWNER_NUMBER'),
        // Minimum seconds between outbound messages in bulk sends (block-risk mitigation).
        'broadcast_throttle_seconds' => env('WAHA_BROADCAST_THROTTLE', 30),
    ],

    'notifications' => [
        // Internal team alerts (new ticket / customer reply) go to the WhatsApp
        // approvals number/group (billing.waha.owner_number) AND this email.
        // Independent of the AI layer — the team is always notified.
        'team_email' => env('NOTIFY_TEAM_EMAIL'),
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
            // Operator fixes proposed by the WordPress agent (post-approval).
            'cache_path' => env('FLYWP_CACHE_PATH', 'servers/{server}/sites/{site}/cache/clear'),
            'restart_path' => env('FLYWP_RESTART_PATH', 'servers/{server}/sites/{site}/restart'),
        ],
    ],

    'ai' => [
        // Optional Tier-1 AI (Stage 5). When api_key is empty the layer is a
        // no-op — tickets are still handled manually, nothing breaks.
        'enabled' => env('AI_ENABLED', false),

        // Provider: 'anthropic' (Claude) or 'openai' (any OpenAI-compatible
        // endpoint — OpenAI, Azure OpenAI, OpenRouter, or a local model server).
        'provider' => env('AI_PROVIDER', 'anthropic'),

        // AI_API_KEY is the generic name; ANTHROPIC_API_KEY kept for back-compat.
        'api_key' => env('AI_API_KEY', env('ANTHROPIC_API_KEY')),
        'base_url' => env('AI_BASE_URL', env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com')),
        'model' => env('AI_MODEL', 'claude-opus-4-8'),
        'effort' => env('AI_EFFORT', 'low'),

        // Editable agent instructions. The persona sets the tone/role; the rules
        // are the guardrails (what's allowed/forbidden). Non-negotiable safety
        // rules are always appended in code on top of these.
        'persona' => env('AI_PERSONA', 'אתה נציג תמיכה של Multi Digital — חברת אחסון ותחזוקת אתרים. דבר בעברית, בנימוס, בקצרה ולעניין.'),
        'rules' => env('AI_RULES', implode("\n", [
            '- בסס תשובות אך ורק על נתוני הלקוח שסופקו; אל תמציא פרטים.',
            '- אל תבטיח החזרים, זיכויים או מועדים שלא אושרו.',
            '- אל תחשוף פרטים פנימיים, מפתחות, או נתונים של לקוחות אחרים.',
            '- בנושאים רגישים (ביטול מנוי, סכסוך תשלום) — המלץ להעביר לנציג אנושי.',
            '- צרף קישור לעדכון כרטיס רק אם הלקוח ביקש לעדכן אמצעי תשלום.',
        ])),
    ],

    'monitoring' => [
        'interval_minutes' => env('MONITOR_INTERVAL_MINUTES', 5),
        'timeout_seconds' => env('MONITOR_TIMEOUT_SECONDS', 10),
        // Consecutive failed checks before an incident is opened.
        'failures_to_incident' => env('MONITOR_FAILURES_TO_INCIDENT', 2),
        // Warn the team when a TLS certificate has this many days (or fewer) left.
        'ssl_warn_days' => env('MONITOR_SSL_WARN_DAYS', 14),
        // Responses slower than this (ms) are flagged as "degraded" (not down).
        'slow_response_ms' => env('MONITOR_SLOW_RESPONSE_MS', 4000),
    ],

    'broadcasts' => [
        'email_chunk_size' => env('BROADCAST_EMAIL_CHUNK', 50),
    ],
];
