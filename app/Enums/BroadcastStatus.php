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
        return str_replace('_', ' ', $this->value);
    }
}
