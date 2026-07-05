<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DunningStatus: string implements HasLabel
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'בתור',
            self::Sent => 'נשלח',
            self::Failed => 'נכשל',
        };
    }
}
