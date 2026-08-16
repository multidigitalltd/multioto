<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin licensing
    |--------------------------------------------------------------------------
    |
    | Selling our own WordPress plugins: a licence key per customer, the sites
    | it may run on, and the update channel those sites pull from. The contract
    | the plugin speaks is documented in docs/license-api.md and must not drift
    | from it — a change here changes what is installed on customers' shops.
    |
    */

    /*
    | The secret every licence key is hashed with, and every download link is
    | signed with. Keys are never stored in the clear: a leaked database must
    | not hand anybody a working key.
    |
    | Changing it invalidates every existing key and every outstanding download
    | link, so it is set once and left alone. Falls back to the application key
    | rather than to a blank string — an empty secret would turn the hash into a
    | public function of the key and the signature into decoration.
    */
    'secret' => env('LICENSE_SERVER_SECRET') ?: env('APP_KEY'),

    /*
    | How long a signed download link stays usable. WordPress follows the link
    | within seconds of asking; an hour is generous and bounds what a link found
    | in a proxy log is worth.
    */
    'download_ttl_minutes' => (int) env('LICENSE_DOWNLOAD_TTL_MINUTES', 60),

    // Private disk + path prefix where release zips live: {path}/{product}/{version}.zip.
    'disk' => env('LICENSE_RELEASE_DISK', 'local'),
    'path' => env('LICENSE_RELEASE_PATH', 'plugin-releases'),

    /*
    | Requests per minute per IP. A site checks in once a day and asks about
    | updates every six hours, so anything above a trickle is somebody probing.
    */
    'rate_limit' => (int) env('LICENSE_RATE_LIMIT_PER_MINUTE', 30),

    /*
    | Defaults reported to WordPress when a release does not state its own.
    | Mirrors the contract document.
    */
    'defaults' => [
        'requires' => '6.4',
        'requires_php' => '8.0',
        'tested' => '9.9',
    ],

    /*
    | How long before expiry the customer is warned that the licence is about to
    | lapse. A licence that stops updating without notice reads as a broken
    | plugin, and the support ticket arrives instead of the renewal.
    */
    'expiry_reminder_days' => [30, 7, 1],

];
