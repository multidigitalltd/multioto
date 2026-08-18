<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /**
     * Approved, started, and still working through its slices.
     *
     * A batch of four hundred price changes is carried out a slice at a time,
     * and the approval must not read as finished while most of the shop is
     * still at its old price — an owner told "done" goes and looks, sees
     * otherwise, and stops trusting the word.
     */
    case Executing = 'executing';
    case Executed = 'executed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'ממתין לאישור',
            self::Approved => 'אושר',
            self::Rejected => 'נדחה',
            self::Executing => 'בביצוע',
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
            self::Executing => 'info',
            self::Executed => 'success',
            self::Failed => 'danger',
        };
    }
}
