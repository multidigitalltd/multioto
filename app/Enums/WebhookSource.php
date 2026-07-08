<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebhookSource: string implements HasLabel
{
    case Cardcom = 'cardcom';
    case Waha = 'waha';
    case Linet = 'linet';
    case Email = 'email';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cardcom => 'קארדקום',
            self::Waha => 'וואטסאפ',
            self::Linet => 'לינט',
            self::Email => 'אימייל',
        };
    }
}
