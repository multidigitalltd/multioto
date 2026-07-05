<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MessageChannel: string implements HasLabel
{
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case InternalNote = 'internal_note';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
