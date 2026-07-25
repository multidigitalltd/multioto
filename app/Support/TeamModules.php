<?php

namespace App\Support;

/**
 * The panel's permission modules — one per navigation group. An admin can
 * limit each team member (agent) to a subset of these; screens whose group
 * was not granted disappear from the navigation AND return 403 on direct
 * URL access. Ungrouped screens (the dashboard) stay open to everyone, and
 * the settings cluster stays admin-only regardless.
 */
class TeamModules
{
    /** module key => the Filament navigation-group label it governs. */
    public const LABELS = [
        'finance' => 'כספים',
        'support' => 'תמיכה',
        'management' => 'ניהול',
    ];

    /** @return array<string, string> key => Hebrew label, for form options. */
    public static function options(): array
    {
        return self::LABELS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** The module key governing a navigation-group label, or null if ungoverned. */
    public static function keyForGroup(?string $group): ?string
    {
        $key = array_search($group, self::LABELS, true);

        return $key === false ? null : $key;
    }
}
