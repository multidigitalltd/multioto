<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'ממתין לאישור',
            self::Approved => 'אושר',
            self::Rejected => 'נדחה',
            self::Executed => 'בוצע',
            self::Failed => 'נכשל',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'gray',
            self::Executed => 'success',
            self::Failed => 'danger',
        };
    }
}
