<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TicketChannel: string implements HasLabel
{
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Form = 'form';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
