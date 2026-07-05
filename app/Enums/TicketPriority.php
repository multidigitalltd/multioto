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
        return match ($this) {
            self::Low => 'נמוכה',
            self::Normal => 'רגילה',
            self::High => 'גבוהה',
            self::Urgent => 'דחופה',
        };
    }
}
