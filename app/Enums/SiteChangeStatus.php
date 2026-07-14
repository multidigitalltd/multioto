<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a recorded site change: applied and reversible, rolled back, or
 * failed to apply.
 */
enum SiteChangeStatus: string implements HasColor, HasLabel
{
    case Applied = 'applied';
    case Reverted = 'reverted';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Applied => 'בוצע',
            self::Reverted => 'שוחזר',
            self::Failed => 'נכשל',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Applied => 'success',
            self::Reverted => 'gray',
            self::Failed => 'danger',
        };
    }
}
