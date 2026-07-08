<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChargeStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'ממתין',
            self::Succeeded => 'הצליח',
            self::Failed => 'נכשל',
        };
    }
}
