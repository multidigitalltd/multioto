<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a console instruction turned into: an approval proposal filed, a
 * background site investigation dispatched, a request to clarify (couldn't
 * resolve the target), or a failure.
 */
enum AgentCommandOutcome: string implements HasColor, HasLabel
{
    case Proposed = 'proposed';
    case Dispatched = 'dispatched';
    case Unclear = 'unclear';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposed => 'הוצעה פעולה לאישור',
            self::Dispatched => 'נשלח לסוכן',
            self::Unclear => 'נדרשת הבהרה',
            self::Failed => 'נכשל',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'success',
            self::Dispatched => 'info',
            self::Unclear => 'warning',
            self::Failed => 'danger',
        };
    }
}
