<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomerStatus: string implements HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Churned = 'churned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'פעיל',
            self::Suspended => 'מושהה',
            self::Churned => 'נטש',
        };
    }
}
