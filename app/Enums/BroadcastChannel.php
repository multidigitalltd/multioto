<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BroadcastChannel: string implements HasLabel
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
