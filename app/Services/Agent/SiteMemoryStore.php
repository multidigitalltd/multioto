<?php

namespace App\Services\Agent;

use App\Models\Site;
use App\Models\SiteMemory;

/**
 * Read/write the durable, per-site memory the agent consults before acting and
 * updates after learning something about a site. Keys are unique per site, so
 * writing an existing key updates it in place.
 */
class SiteMemoryStore
{
    /** The value stored under a key, or null when nothing is remembered. */
    public function get(Site $site, string $key): ?string
    {
        return $site->memories()->where('key', $key)->value('value');
    }

    /** Remember (or overwrite) a value under a key. */
    public function put(Site $site, string $key, ?string $value, ?string $by = null): SiteMemory
    {
        return $site->memories()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $by],
        );
    }

    /** Forget a key. */
    public function forget(Site $site, string $key): void
    {
        $site->memories()->where('key', $key)->delete();
    }

    /**
     * Everything remembered about a site, as a key => value map — the block of
     * context handed to the agent before it plans an action.
     *
     * @return array<string, string|null>
     */
    public function all(Site $site): array
    {
        return $site->memories()->orderBy('key')->pluck('value', 'key')->all();
    }
}
