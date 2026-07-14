<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer self-service portal
    |--------------------------------------------------------------------------
    |
    | A password-less area where a customer can see their invoices and open
    | tickets and replace their card. Sign-in is a magic link mailed (and, when
    | possible, WhatsApp'd) to the address already on the customer's record.
    |
    */

    // How long a sign-in link stays valid, in minutes.
    'login_link_ttl_minutes' => (int) env('PORTAL_LOGIN_LINK_TTL_MINUTES', 30),

];
