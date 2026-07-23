<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kind of customer-facing message recorded in the outbound notification log —
 * enough to tell a dunning reminder apart from a welcome or a broadcast at a
 * glance, and to filter the log by purpose.
 */
enum NotificationType: string implements HasColor, HasLabel
{
    case Dunning = 'dunning';
    case PaymentLink = 'payment_link';
    case Welcome = 'welcome';
    case CardLink = 'card_link';
    case Ticket = 'ticket';
    case TicketReply = 'ticket_reply';
    case Broadcast = 'broadcast';
    case CustomerCard = 'customer_card';
    case DomainRenewal = 'domain_renewal';
    case IncidentResolved = 'incident_resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dunning => 'גבייה (דאנינג)',
            self::PaymentLink => 'קישור תשלום',
            self::Welcome => 'ברוך הבא',
            self::CardLink => 'קישור לכרטיס',
            self::Ticket => 'עדכון פנייה',
            self::TicketReply => 'תשובת נציג',
            self::Broadcast => 'דיוור',
            self::CustomerCard => 'כרטיס לקוח חתום',
            self::DomainRenewal => 'חידוש דומיין',
            self::IncidentResolved => 'תקלה טופלה אוטומטית',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Dunning => 'danger',
            self::PaymentLink => 'warning',
            self::Welcome => 'success',
            self::CardLink => 'info',
            self::Ticket, self::TicketReply => 'primary',
            self::Broadcast => 'gray',
            self::CustomerCard => 'success',
            self::DomainRenewal => 'warning',
            self::IncidentResolved => 'success',
        };
    }
}
