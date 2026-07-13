<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of an internal team task: open → in progress → done.
 */
enum TaskStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'פתוח',
            self::InProgress => 'בביצוע',
            self::Done => 'הושלם',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::InProgress => 'warning',
            self::Done => 'success',
        };
    }
}
