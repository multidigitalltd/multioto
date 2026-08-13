<?php

namespace App\Jobs;

use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CountryRulesSnapshot;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read the account's Cloudflare country rules and store the reading.
 *
 * This is the work that used to happen inside the request that opened the
 * country-rules window: two API calls per zone, one after another, while the
 * operator watched a spinner that eventually gave up. It belongs in a queue —
 * the panel now shows the last reading immediately and this refreshes it.
 *
 * Unique only until it STARTS, because the refresh button and the hourly
 * schedule can both ask for one and two identical readings waiting in the queue
 * would produce the same answer twice. Once a reading is under way it no longer
 * blocks a new request — a change made from the panel needs a reading that began
 * after it, and the one in flight began before.
 */
class RefreshCloudflareCountryRulesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A large account is many round trips; the default 60s is not enough. */
    public int $timeout = 600;

    /** Long enough to cover a slow run, short enough that a dead worker cannot block refreshes for the day. */
    public int $uniqueFor = 900;

    public function handle(CloudflareClient $client): void
    {
        $token = CountryRulesSnapshot::token();

        // Nothing to read. A reading taken under a previous token is not shown
        // either way — the stored reading is bound to the token that produced it,
        // so it can never be presented as the state of a different account.
        if ($token === '') {
            return;
        }

        CountryRulesSnapshot::markRefreshing();

        // Taken BEFORE the reading starts. A change made while this runs bumps
        // the revision, and the reading is then stored for what it is: the state
        // as of before that change.
        $revision = CountryRulesSnapshot::revision();

        try {
            $snapshot = $client->countrySnapshot($token);
        } catch (\Throwable $e) {
            CountryRulesSnapshot::storeFailure($e->getMessage());
            CountryRulesSnapshot::finishedRefreshing();

            throw $e;
        }

        $snapshot['ok']
            ? CountryRulesSnapshot::store($snapshot, $revision)
            : CountryRulesSnapshot::storeFailure($snapshot['message']);

        CountryRulesSnapshot::finishedRefreshing();
    }
}
