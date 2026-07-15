<?php

namespace App\Services\Agent;

use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Classifies site tools into risk tiers and answers the policy question "may
 * this tool run on this site at all?". The tier drives how a proposal is
 * presented and what is allowed; the human-approval gate itself is
 * unconditional for every state-changing action.
 *
 * Tiers: 0 read-only · 1 safe/reversible · 2 change · 3 destructive.
 * Unknown tools default to tier 2 so nothing unclassified passes as harmless.
 */
class SiteToolCatalog
{
    public const TIER_LABELS = [
        0 => 'קריאה בלבד',
        1 => 'בטוח והפיך',
        2 => 'שינוי',
        3 => 'הרסני',
    ];

    /** The risk tier of a tool, by configured name-substring rules. */
    public function tier(string $tool): int
    {
        $tool = Str::lower($tool);

        // Highest tier first: a name matching both "delete" and "list"
        // (e.g. delete_from_list) must classify as destructive.
        foreach ([3, 2, 1, 0] as $tier) {
            foreach ((array) config("agent.risk.{$tier}", []) as $needle) {
                if (Str::contains($tool, Str::lower((string) $needle))) {
                    return $tier;
                }
            }
        }

        return 2;
    }

    public function tierLabel(string $tool): string
    {
        return self::TIER_LABELS[$this->tier($tool)];
    }

    /**
     * Whether the tool may run on this site at all. Destructive (tier-3) tools
     * are confined to staging sites; everything else is allowed anywhere —
     * subject, always, to the approval gate.
     */
    public function allowedOn(Site $site, string $tool): bool
    {
        return $this->tier($tool) < 3 || $site->environment === 'staging';
    }
}
