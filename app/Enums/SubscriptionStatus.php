<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: string implements HasLabel
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trialing => 'תקופת ניסיון',
            self::Active => 'פעיל',
            self::PastDue => 'בפיגור',
            self::Suspended => 'מושהה',
            self::Canceled => 'בוטל',
        };
    }
}
