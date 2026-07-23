<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TicketChannel: string implements HasLabel
{
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Form = 'form';
    case Portal = 'portal';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Whatsapp => 'וואטסאפ',
            self::Email => 'אימייל',
            self::Form => 'טופס',
            self::Portal => 'פורטל',
            self::Manual => 'ידני',
        };
    }
}
