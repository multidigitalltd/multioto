<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TicketStatus: string implements HasLabel
{
    case Open = 'open';
    /**
     * בטיפול — הצוות עובד על הפנייה, והלקוח קיבל על כך עדכון.
     *
     * זה לא "פתוח" ולא "ממתין ללקוח": הכדור אצלנו, אבל הפנייה כבר נענתה. לכן
     * היא יוצאת ממוני הפניות שממתינות למענה — פנייה שכבר ענינו עליה ואנחנו
     * עובדים עליה אינה פנייה שלא נענתה, וספירתה ככזו הופכת את המונה למספר
     * שמפסיקים להאמין לו.
     */
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'פתוח',
            self::InProgress => 'בטיפול',
            self::Pending => 'ממתין ללקוח',
            self::OnHold => 'בהמתנה',
            self::Resolved => 'טופל',
            self::Closed => 'סגור',
        };
    }
}
