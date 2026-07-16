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

    /**
     * The effective tier for a tool ON A SPECIFIC SITE. The MCP destructive hint
     * can only ESCALATE (a tool declaring itself destructive is tier 3, confined
     * to staging, regardless of a benign name); a read-only hint never lowers a
     * name that already looks dangerous. Unhinted tools use the conservative
     * name-substring tier (unknown → tier 2, approval required).
     */
    public function resolveTier(Site $site, string $tool): int
    {
        if (($this->hint($site, $tool)['destructive'] ?? false) === true) {
            return 3;
        }

        return $this->tier($tool);
    }

    public function resolveTierLabel(Site $site, string $tool): string
    {
        return self::TIER_LABELS[$this->resolveTier($site, $tool)];
    }

    /**
     * Whether a tool may run as a READ during AI investigation — the only path
     * that skips the approval gate. Default-deny: the tool must both declare a
     * read-only hint AND carry no dangerous name pattern. A mutating tool whose
     * name merely contains "list"/"get"/"log" can never qualify.
     */
    public function isReadOnly(Site $site, string $tool): bool
    {
        return ($this->hint($site, $tool)['read_only'] ?? false) === true
            && $this->tier($tool) === 0;
    }

    /**
     * Whether the tool may run on this site at all. Destructive tools (by hint
     * or name) are confined to staging; everything else is allowed anywhere —
     * subject, always, to the approval gate.
     */
    public function allowedOn(Site $site, string $tool): bool
    {
        return $this->resolveTier($site, $tool) < 3 || $site->environment === 'staging';
    }

    /**
     * The cached MCP behaviour hints for a tool on a site.
     *
     * @return array{read_only?: bool, destructive?: bool}
     */
    private function hint(Site $site, string $tool): array
    {
        foreach ((array) data_get($site->mcp_capabilities, 'tools', []) as $entry) {
            if (($entry['name'] ?? null) === $tool) {
                return ['read_only' => (bool) ($entry['read_only'] ?? false), 'destructive' => (bool) ($entry['destructive'] ?? false)];
            }
        }

        return [];
    }
}
