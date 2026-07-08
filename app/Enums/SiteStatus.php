<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SiteStatus: string implements HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'פעיל',
            self::Suspended => 'מושהה',
        };
    }
}
