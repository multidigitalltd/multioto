<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TokenStatus: string implements HasLabel
{
    case Active = 'active';
    case Expired = 'expired';
    case Replaced = 'replaced';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
