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
        $module = static::accessModule();

        if ($module !== null && ! (auth()->user()?->canAccessModule($module) ?? false)) {
            return false;
        }

        return parent::canAccess();
    }

    /**
     * The module key governing this screen. Defaults to the navigation group;
     * screens without one (e.g. clustered resources, whose sidebar entry is
     * the cluster's) override this so a direct URL is still gated.
     */
    protected static function accessModule(): ?string
    {
        return TeamModules::keyForGroup(static::getNavigationGroup());
    }
}
