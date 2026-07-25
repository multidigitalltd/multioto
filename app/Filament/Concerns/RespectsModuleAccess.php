<?php

namespace App\Filament\Concerns;

use App\Support\TeamModules;

/**
 * Gate a Filament resource or page by the per-user module grants an admin set
 * on the team member (users.allowed_modules). canAccess() controls both the
 * navigation entry and direct URL access (resource pages 403 through
 * authorizeResourceAccess, standalone pages through their mount gate), so a
 * screen whose module was not granted is fully unreachable — not just hidden.
 */
trait RespectsModuleAccess
{
    public static function canAccess(): bool
    {
        $module = TeamModules::keyForGroup(static::getNavigationGroup());

        if ($module !== null && ! (auth()->user()?->canAccessModule($module) ?? false)) {
            return false;
        }

        return parent::canAccess();
    }
}
