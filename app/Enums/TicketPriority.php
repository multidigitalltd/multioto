<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TicketPriority: string implements HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
