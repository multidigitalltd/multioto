<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A team member's access level in the admin panel.
 *
 * - Admin: full access — settings, integrations, team management, everything.
 * - Agent: day-to-day support and operations, but not the settings/integration
 *   pages or team-member management (see the AdminOnly concern).
 */
enum UserRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'מנהל',
            self::Agent => 'נציג',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Agent => 'gray',
        };
    }
}
