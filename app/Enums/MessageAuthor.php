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
        return match ($this) {
            self::Customer => 'לקוח',
            self::Agent => 'נציג',
            self::System => 'מערכת',
            self::Ai => 'סוכן AI',
        };
    }
}
