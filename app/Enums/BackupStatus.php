<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of one backup archive — and, reused, of a restore attempt from it.
 */
enum BackupStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => 'בתהליך',
            self::Completed => 'הושלם',
            self::Failed => 'נכשל',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
