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

    // Business branding. The uploaded logo (public disk path) is shown wherever
    // we present to the customer — signup form, thank-you page, emails, the
    // signed customer-card PDF — and as the admin panel brand. Editable in
    // הגדרות ← מייל ושולח.
    'branding' => [
        'logo_path' => env('BRANDING_LOGO_PATH'),
        // Footer shown at the bottom of every customer email. Blank → a default
        // built from the sender name and current year. Editable in הגדרות ← מייל.
        'email_footer' => env('BRANDING_EMAIL_FOOTER'),
    ],

    // How long a signed card-update link (embedded in dunning messages) stays
    // valid. Short-lived so a forwarded/leaked message can't be reused forever.
    'card_update_link_ttl_hours' => env('CARD_UPDATE_LINK_TTL_HOURS', 72),

    // How long a payment-demand link stays valid. Longer than a card-update link
    // — a demand may sit unpaid for a while — but still bounded. A canceled
    // demand stops working immediately regardless of this TTL.
    'payment_link_ttl_hours' => env('PAYMENT_LINK_TTL_HOURS', 24 * 14),

    // Bank-transfer details shown on a payment demand that offers a transfer
    // option. Free text (account name, bank/branch, account number, or an IBAN).
    'bank_transfer_details' => env('BANK_TRANSFER_DETAILS'),

    // Automatic follow-up on an unpaid payment demand: after `interval_days` of
    // silence resend the request (link/transfer), up to `max_reminders` times,
    // then stop. Set max_reminders to 0 to disable reminders entirely.
    'demands' => [
        'reminder_interval_days' => (int) env('DEMAND_REMINDER_INTERVAL_DAYS', 3),
        'max_reminders' => (int) env('DEMAND_MAX_REMINDERS', 2),
    ],

    /*
     | Public signup form (/join). The customer fills their details, signs, and
     | picks how they'll pay. The non-card methods show setup instructions the
     | team can edit from הגדרות ← טופס הרשמה (overlaid onto these defaults).
     */
    'signup' => [
        'instructions' => [
            // Standing order (bank debit authorisation) — our Kesher institution
            // code and the digital-authorisation link.
            'standing_order' => env('SIGNUP_STANDING_ORDER_INSTRUCTIONS', implode("\n", [
                'יש להקים בבנק הרשאה לחיוב חשבון עבור קוד מוסד 26851 — מולטי דיגיטל בע״מ.',
                'לחלופין ניתן להקים את ההרשאה באופן דיגיטלי בקישור:',
                'https://ultra.kesherhk.info/extern/paymentPage/311928/',
            ])),
            // Bank transfer — the account to transfer to. Fill the real details
            // in הגדרות ← טופס הרשמה.
            'bank_transfer' => env('SIGNUP_BANK_TRANSFER_INSTRUCTIONS', 'פרטי החשבון להעברה בנקאית יימסרו על ידי הצוות — מלאו אותם בהגדרות ← טופס הרשמה.'),
            // Cheques (advance / prepayment).
            'checks' => env('SIGNUP_CHECKS_INSTRUCTIONS', 'לתשלום בצ׳קים (מקדמה / תשלום מראש) ניצור עמכם קשר לתיאום מסירת הצ׳קים.'),
        ],

        // Shown on the payment step: where to download our up-to-date
        // bookkeeping / tax-withholding certificates. Any http(s) link becomes
        // clickable. Editable in הגדרות ← טופס הרשמה.
        'tax_approval_notice' => env('SIGNUP_TAX_APPROVAL_NOTICE', implode("\n", [
            'אם דרושים לכם אישור ניהול ספרים או אישור ניכוי מס במקור — ניתן להוריד אישור עדכני בקישור:',
            'https://taxinfo.taxes.gov.il/gmishurim/firstPage.aspx',
            'מספר התיק שלנו: 516171303 — מולטי דיגיטל בע״מ.',
        ])),
    ],

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

        // Whether to attach a Cardcom Document object, and of what type. This
        // account has NO Cardcom documents module — invoicing is done entirely
        // in Linet — so we send NO Document by default. Asking Cardcom for one
        // fails with "אין מודול מסמכים" ('Auto' resolves to an Invoice the
        // account can't create). The card-holder still sees the charge via the
        // top-level ProductName, and Linet issues the real tax invoice.
        //
        // If your terminal is configured to MANDATE a document you'll get error
        // 5046 ("No InvoiceHead data was send") instead — turn off the terminal's
        // automatic-document setting in Cardcom (since Linet is the invoicer),
        // rather than turning a document back on here. Options:
        //  - '' (default) — send NO Document. Correct when Linet is the invoicer.
        //  - 'Auto' — Cardcom picks the type (needs a Cardcom documents module).
        //  - 'Order' — a NON-fiscal order document (needs the module too).
        //  - 'TaxInvoiceAndReceipt' — a fiscal invoice+receipt from Cardcom (then
        //    disable Linet issuance to avoid duplicate invoices).
        'document_type' => env('CARDCOM_DOCUMENT_TYPE', ''),

        // Automatic reconciliation of charges stuck on "ממתין" (a lost completion
        // webhook). A hosted charge/demand is looked up against Cardcom once it's
        // at least `reconcile_after_minutes` old (give the webhook a chance first)
        // and until it's `reconcile_max_age_days` old (then stop chasing it). Only
        // a CONFIRMED success finalises the charge — a card is never re-charged.
        'reconcile_after_minutes' => (int) env('CARDCOM_RECONCILE_AFTER_MINUTES', 15),
        'reconcile_max_age_days' => (int) env('CARDCOM_RECONCILE_MAX_AGE_DAYS', 14),
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
        // Document-type code for a proforma / "חשבונית עסקה" — a NON-fiscal demand
        // for payment issued when a payment demand is created (before payment).
        // Leave unset to skip proforma issuance entirely (demands still go out).
        'doctype_proforma' => env('LINET_DOCTYPE_PROFORMA'),
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
        // docCheq payment-method codes per how the customer pays. The default
        // (payment_type) is credit card; manual payers get their own Linet code
        // so the tax invoice records the real method. Both fall back to
        // payment_type when left unset.
        //  - payment_type_bank_transfer: העברה בנקאית
        //  - payment_type_standing_order: הו״ק בנקאית (מס״ב)
        'payment_type' => env('LINET_PAYMENT_TYPE', 3),
        'payment_type_bank_transfer' => env('LINET_PAYMENT_TYPE_BANK_TRANSFER'),
        'payment_type_standing_order' => env('LINET_PAYMENT_TYPE_STANDING_ORDER'),
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
        // Fixed signature appended to outbound support replies. Editable in
        // הגדרות ← דואר. Email is the primary use; WhatsApp is optional and
        // usually shorter (or empty). Blank = no signature appended.
        'reply_signature' => env('REPLY_SIGNATURE'),
        'reply_signature_whatsapp' => env('REPLY_SIGNATURE_WHATSAPP'),
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

        // Guardrails for MENA to CUSTOMERS (ticket replies / drafts). Editable in
        // the panel. Non-negotiable safety rules are appended in code on top.
        'rules' => env('AI_RULES', implode("\n", [
            '- בסס תשובות אך ורק על נתוני הלקוח שסופקו; אל תמציא פרטים.',
            '- אל תבטיח החזרים, זיכויים או מועדים שלא אושרו.',
            '- אל תחשוף פרטים פנימיים, מפתחות, או נתונים של לקוחות אחרים.',
            '- בנושאים רגישים (ביטול מנוי, סכסוך תשלום) — המלץ להעביר לנציג אנושי.',
            '- צרף קישור לעדכון כרטיס רק אם הלקוח ביקש לעדכן אמצעי תשלום.',
        ])),

        // Guardrails for OPERATING ON SITES (the MCP site agent). Separate from
        // the customer-reply rules above — a different job with different limits.
        // Hard enforcement (risk tiers, staging-only, approval gate, kill-switch)
        // still applies in code regardless of what is written here.
        'site_rules' => env('AI_SITE_RULES', implode("\n", [
            '- חקור תמיד קודם בכלי קריאה; אל תשנה דבר לפני שהבנת את שורש הבעיה.',
            '- הצע את הפעולה הבטוחה, המינימלית וההפיכה ביותר שפותרת את הבעיה.',
            '- אל תיגע בהגדרות אבטחה, במשתמשים או במפתחות ללא הצדקה מפורשת.',
            '- אל תבצע שינויים גורפים (החלפת מחרוזות במסד, מחיקות המוניות) בלי אישור מפורש.',
            '- אם אינך בטוח — אל תציע פעולה; כתוב מה בדקת והמלץ על בדיקה ידנית.',
        ])),

        // Style guide distilled from past agent replies (StyleLearner). Refreshed
        // from the AI-agent settings page; fed into every draft so it matches how
        // the team actually writes. Blank until first learned.
        'style_summary' => env('AI_STYLE_SUMMARY'),

        // Token prices in USD per 1,000,000 tokens, as [input, output]. Used only
        // to estimate spend for the AI-agent dashboard — the provider's invoice is
        // the source of truth. Matched by the most-specific key contained in the
        // model name; '*' is the fallback for anything unlisted. Update when a
        // provider changes prices. (Prices as of mid-2026.)
        'pricing' => [
            'gemini-2.5-flash-lite' => [0.10, 0.40],
            'gemini-2.5-flash' => [0.30, 2.50],
            'gemini-2.5-pro' => [1.25, 10.00],
            'gemini-3.1-flash-lite' => [0.25, 1.50],
            'gemini-3.5-flash' => [1.50, 9.00],
            // Rolling aliases now resolve to the 3.x line (Google shut the 2.5
            // Flash models down mid-2026): flash-latest → 3.5 Flash,
            // flash-lite-latest → 3.1 Flash-Lite.
            'gemini-flash-lite' => [0.25, 1.50],
            'gemini-flash' => [1.50, 9.00],
            'gemini-pro' => [1.25, 10.00],
            'gpt-4o-mini' => [0.15, 0.60],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4o' => [2.50, 10.00],
            'claude-opus' => [15.00, 75.00],
            'claude-sonnet' => [3.00, 15.00],
            'claude-haiku' => [0.80, 4.00],
            '*' => [1.00, 5.00],
        ],
    ],

    'monitoring' => [
        'interval_minutes' => env('MONITOR_INTERVAL_MINUTES', 5),
        'timeout_seconds' => env('MONITOR_TIMEOUT_SECONDS', 10),
        // Consecutive failed checks before an incident is opened.
        'failures_to_incident' => env('MONITOR_FAILURES_TO_INCIDENT', 2),
        // Warn the team when a TLS certificate has this many days (or fewer) left.
        'ssl_warn_days' => env('MONITOR_SSL_WARN_DAYS', 14),
        // Warn the team when the DOMAIN registration has this many days (or fewer)
        // left — a lapsed domain takes the whole site down. Longer than SSL since
        // domain renewal is slower/manual.
        'domain_warn_days' => env('MONITOR_DOMAIN_WARN_DAYS', 30),
        // Responses slower than this (ms) are flagged as "degraded" (not down).
        'slow_response_ms' => env('MONITOR_SLOW_RESPONSE_MS', 4000),

        // Monthly monitoring report emailed to the customer on their billing day.
        'monthly_report' => [
            // Off by default — turn on once the team is happy with the content.
            'enabled' => env('MONITOR_MONTHLY_REPORT_ENABLED', false),
            // Auto-send only when EVERY site met this uptime %. Below it the report
            // waits for manual approval, so a bad month never auto-mails a customer.
            'auto_uptime_threshold' => env('MONITOR_MONTHLY_REPORT_THRESHOLD', 99.9),
            // The reporting window (days) the report summarises.
            'window_days' => env('MONITOR_MONTHLY_REPORT_WINDOW_DAYS', 30),
        ],
    ],

    'broadcasts' => [
        'email_chunk_size' => env('BROADCAST_EMAIL_CHUNK', 50),
    ],

    'system' => [
        // In-panel system log ("מערכת ועדכונים") retention: rows older than this
        // many days are pruned nightly by the scheduler.
        'log_retention_days' => env('SYSTEM_LOG_RETENTION_DAYS', 30),
    ],

    'support' => [
        // Auto follow-up for a ticket stuck "waiting for customer" (Pending):
        // after reminder_days of silence the customer gets one reminder, and
        // after close_days it is auto-closed. Set enabled=false to switch off.
        'pending_followup' => [
            'enabled' => (bool) env('SUPPORT_PENDING_FOLLOWUP', true),
            'reminder_days' => (int) env('SUPPORT_PENDING_REMINDER_DAYS', 3),
            'close_days' => (int) env('SUPPORT_PENDING_CLOSE_DAYS', 7),
        ],

        // Daily email reminder to a team member about their open tasks that are
        // due today or overdue. Set enabled=false to switch off.
        'task_reminders' => [
            'enabled' => (bool) env('SUPPORT_TASK_REMINDERS', true),
            'time' => (string) env('SUPPORT_TASK_REMINDER_TIME', '08:30'),
        ],

        // Inbound attachments (images/files a customer sends on WhatsApp or
        // email). Stored on a PRIVATE disk and served only behind panel auth.
        'attachments' => [
            'disk' => env('ATTACHMENT_DISK', 'local'),
            'max_bytes' => (int) env('ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024), // 10 MB
            // Allow-list only. Deliberately excludes SVG (scriptable) and every
            // executable/PHP type — the extension is derived from the MIME, so a
            // ".php" filename can never be written.
            'allowed_mimes' => [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/heic' => 'heic',   // iPhone photos
                'image/heif' => 'heif',
                'image/heic-sequence' => 'heic',   // finfo reports these for HEIF sequences
                'image/heif-sequence' => 'heif',
                'application/pdf' => 'pdf',
                'text/plain' => 'txt',
                // CSV sniffs as text/csv on some libmagic versions and text/plain
                // on others; the text/plain case keeps the ".csv" via the sender
                // extension (see AttachmentStore::resolveExtension).
                'text/csv' => 'csv',
                'application/csv' => 'csv',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/vnd.ms-powerpoint' => 'ppt',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                'application/zip' => 'zip',
                'application/x-zip-compressed' => 'zip',
                // Media customers commonly send on WhatsApp (voice notes, clips).
                'audio/ogg' => 'ogg',       // WhatsApp voice notes
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'audio/aac' => 'aac',
                'audio/amr' => 'amr',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
                'video/mp4' => 'mp4',
                'video/quicktime' => 'mov',
                'video/3gpp' => '3gp',
                'video/webm' => 'webm',
            ],
        ],
    ],

    /*
     | Proactive reminders — a daily internal digest to the team so nothing
     | slips before it becomes a problem (an upcoming renewal, a card about to
     | expire, money already owed). Internal only: the owner decides whether to
     | contact a customer, honouring the "no customer message without approval"
     | rule.
     */
    'reminders' => [
        // Flag subscriptions whose next charge is within this many days.
        'renewal_days' => env('REMINDER_RENEWAL_DAYS', 3),
        // Flag saved cards expiring within this many whole months (0 = this
        // month only; 1 = this month and next).
        'card_expiry_months' => env('REMINDER_CARD_EXPIRY_MONTHS', 1),
    ],
];
