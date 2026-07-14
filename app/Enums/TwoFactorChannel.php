<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a team member's one-time login code is delivered: their email inbox
 * or their WhatsApp number.
 */
enum TwoFactorChannel: string implements HasLabel
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'אימייל',
            self::Whatsapp => 'וואטסאפ',
        };
    }
}
