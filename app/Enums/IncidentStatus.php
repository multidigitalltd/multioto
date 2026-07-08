<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IncidentStatus: string implements HasLabel
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'פתוח',
            self::Resolved => 'טופל',
        };
    }
}
