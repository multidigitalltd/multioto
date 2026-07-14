<?php

namespace App\Filament\Concerns;

/**
 * Restrict a Filament resource or page to admins. Applied to the settings and
 * team-management screens so agents get the day-to-day panel without reaching
 * integration secrets, mail/signup settings or user management. canAccess()
 * gates both the route and its navigation entry.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
