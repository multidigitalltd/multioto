<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MessageAuthor: string implements HasLabel
{
    case Customer = 'customer';
    case Agent = 'agent';
    case System = 'system';
    case Ai = 'ai';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
