<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Opportunity radar
    |--------------------------------------------------------------------------
    |
    | Turns what the system already knows about each site (accessibility and
    | legal findings, vulnerabilities, speed, SEO basics, broken links, an old
    | PHP) into a concrete, priced list of work the team can offer. The prices
    | are ILS agorot — starting points for a quote, shown to the team only and
    | never to a customer automatically.
    |
    */
    'opportunities' => [
        'enabled' => (bool) env('OPPORTUNITY_RADAR_ENABLED', true),

        // How many internal links from the homepage are probed for 404s. Bounded
        // so a weekly sweep over every site stays cheap.
        'link_sample' => (int) env('OPPORTUNITY_LINK_SAMPLE', 15),

        // A page slower than this (ms, averaged over the last week) is worth a
        // speed engagement.
        'slow_response_ms' => (int) env('OPPORTUNITY_SLOW_MS', 2500),

        // PHP below this is a support/security risk worth upgrading.
        'min_php_version' => env('OPPORTUNITY_MIN_PHP', '8.1'),

        // Indicative price per opportunity type (agorot — 90000 = ₪900).
        'prices' => [
            'accessibility' => (int) env('PRICE_ACCESSIBILITY', 180000),
            'legal_docs' => (int) env('PRICE_LEGAL_DOCS', 90000),
            'vulnerabilities' => (int) env('PRICE_VULNERABILITIES', 60000),
            'reputation' => (int) env('PRICE_REPUTATION', 80000),
            'speed' => (int) env('PRICE_SPEED', 150000),
            'broken_links' => (int) env('PRICE_BROKEN_LINKS', 40000),
            'seo_basics' => (int) env('PRICE_SEO_BASICS', 70000),
            'php_upgrade' => (int) env('PRICE_PHP_UPGRADE', 50000),
            'monitoring' => (int) env('PRICE_MONITORING', 30000),
        ],
    ],

];
