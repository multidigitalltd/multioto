<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebhookSource: string implements HasLabel
{
    case Cardcom = 'cardcom';
    case Waha = 'waha';
    case Linet = 'linet';
    case Email = 'email';
    case Kesher = 'kesher';
    case WhatsappCloud = 'whatsapp_cloud';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cardcom => 'קארדקום',
            self::Waha => 'וואטסאפ',
            self::Linet => 'לינט',
            self::Email => 'אימייל',
            self::Kesher => 'קשר',
            self::WhatsappCloud => 'וואטסאפ רשמי (סוכן האתר)',
        };
    }
}
