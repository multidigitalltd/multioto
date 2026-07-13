<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TicketStatus: string implements HasLabel
{
    case Open = 'open';
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'פתוח',
            self::Pending => 'ממתין ללקוח',
            self::OnHold => 'בהמתנה',
            self::Resolved => 'טופל',
            self::Closed => 'סגור',
        };
    }
}
