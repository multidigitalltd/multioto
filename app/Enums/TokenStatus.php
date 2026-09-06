<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TokenStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Expired = 'expired';
    case Replaced = 'replaced';

    /**
     * Taken off the customer's file by hand. Distinct from Replaced, which is
     * what a card becomes when a NEWER one arrives: removed means somebody
     * decided this card should not be charged again, and nothing took its
     * place. Keeping the row (rather than deleting it) keeps the charges that
     * were collected on it attached to the card they were collected on.
     */
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'פעיל',
            self::Expired => 'פג תוקף',
            self::Replaced => 'הוחלף',
            self::Removed => 'הוסר',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Expired => 'danger',
            self::Replaced, self::Removed => 'gray',
        };
    }
}
