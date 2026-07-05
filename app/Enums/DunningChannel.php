<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DunningChannel: string implements HasLabel
{
    case Whatsapp = 'whatsapp';
    case Email = 'email';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
