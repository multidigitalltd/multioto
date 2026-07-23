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

        // How long a fetched feed is cached before it is refreshed (hours). The
        // feed is large, so we never fetch it per-site.
        'cache_hours' => (int) env('VULN_FEED_CACHE_HOURS', 24),
    ],

];
