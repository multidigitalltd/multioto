<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BroadcastStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'טיוטה',
            self::Scheduled => 'מתוזמן',
            self::Sending => 'בשליחה',
            self::Sent => 'נשלח',
        };
    }
}
