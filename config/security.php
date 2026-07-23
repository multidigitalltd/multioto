<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site vulnerability scanning
    |--------------------------------------------------------------------------
    |
    | Connected WordPress sites are matched against a public vulnerability feed
    | so the team is warned when an installed plugin/theme/core version is known
    | to be vulnerable. The default source is the Wordfence Intelligence feed —
    | free and needs no API key. WPScan is supported as an optional alternative
    | for teams that hold a token (per-plugin API, rate-limited on the free tier).
    |
    */
    'vulnerabilities' => [
        // Master switch for the scan job.
        'enabled' => (bool) env('VULN_SCAN_ENABLED', true),

        // 'wordfence' (default, keyless) or 'wpscan' (needs a token).
        'source' => env('VULN_FEED_SOURCE', 'wordfence'),

        // Wordfence Intelligence production feed — a single JSON of every known
        // WordPress vulnerability. Public, no key.
        'wordfence_feed_url' => env(
            'WORDFENCE_FEED_URL',
            'https://www.wordfence.com/api/intelligence/v2/vulnerabilities/production',
        ),

        // Optional WPScan API token (https://wpscan.com/api). Only used when
        // source = 'wpscan'.
        'wpscan_token' => env('WPSCAN_API_TOKEN'),

        // Feed URL used when source = 'wpscan'. WPScan has no single bulk feed on
        // the free tier, so this must point at a Wordfence-compatible feed the
        // team supplies; without it (or a token) the wpscan source is treated as
        // unavailable rather than silently using Wordfence.
        'wpscan_feed_url' => env('WPSCAN_FEED_URL'),

        // How long a fetched feed is cached before it is refreshed (hours). The
        // feed is large, so we never fetch it per-site.
        'cache_hours' => (int) env('VULN_FEED_CACHE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain reputation / blocklist check
    |--------------------------------------------------------------------------
    |
    | Each site's domain is checked against public spam/malware blocklists so the
    | team is warned if a customer's site is flagged (which hurts email delivery
    | and search ranking). URLhaus (abuse.ch) is keyless and also reports the
    | Spamhaus DBL / SURBL status for the host. Google Safe Browsing is used in
    | addition when an API key is supplied.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Team-action audit log
    |--------------------------------------------------------------------------
    */
    'audit' => [
        // How long team-action audit entries are kept before the nightly prune
        // removes them. Audit data is compliance-relevant, so the default is long.
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | DNS-change watch
    |--------------------------------------------------------------------------
    |
    | Each monitored site's domain is snapshotted daily (A/MX/NS records) and
    | compared with the previous snapshot. An unexpected change — the site
    | suddenly pointing elsewhere, mail rerouted, nameservers replaced — is a
    | classic hijack/migration signal the team should hear about immediately.
    |
    */
    'dns_watch' => [
        'enabled' => (bool) env('DNS_WATCH_ENABLED', true),
    ],

    'reputation' => [
        'enabled' => (bool) env('REPUTATION_SCAN_ENABLED', true),

        // URLhaus host lookup (abuse.ch) — free, no key. Reports known malware
        // URLs on the host plus its Spamhaus DBL / SURBL blocklist status.
        'urlhaus_host_url' => env('URLHAUS_HOST_URL', 'https://urlhaus-api.abuse.ch/v1/host/'),

        // Optional Google Safe Browsing API key (https://developers.google.com/
        // safe-browsing). When set, the domain is also checked against Google's
        // malware/phishing lists.
        'safe_browsing_key' => env('GOOGLE_SAFE_BROWSING_KEY'),
    ],

];
