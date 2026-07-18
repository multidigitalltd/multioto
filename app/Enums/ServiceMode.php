<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How the team is operating on a marked day — surfaced to the agent so a new
 * ticket's acknowledgement sets the right expectation.
 */
enum ServiceMode: string implements HasColor, HasLabel
{
    case Reduced = 'reduced';
    case UrgentOnly = 'urgent_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::Reduced => 'מתכונת מצומצמת (ייתכנו עיכובים)',
            self::UrgentOnly => 'דחוף בלבד',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reduced => 'warning',
            self::UrgentOnly => 'danger',
        };
    }

    /** A guidance line for the AI, describing how to set expectations. */
    public function agentGuidance(): string
    {
        return match ($this) {
            self::Reduced => 'הצוות עובד היום במתכונת מצומצמת — יידע את הלקוח בעדינות שייתכן עיכוב במענה, מבלי להרתיע.',
            self::UrgentOnly => 'היום הצוות מטפל בפניות דחופות בלבד — יידע את הלקוח שפניות שאינן דחופות ייענו מאוחר יותר, ואם זה דחוף שיציין זאת.',
        };
    }

    /**
     * A fixed, safe customer-facing line for the non-AI (template) path, so the
     * expectation is set even when the AI acknowledgement is off. Never contains
     * the internal note.
     */
    public function customerNotice(): string
    {
        return match ($this) {
            self::Reduced => 'לידיעה: אנחנו עובדים כעת במתכונת מצומצמת וייתכן עיכוב קל במענה. נטפל בפנייתך בהקדם.',
            self::UrgentOnly => 'לידיעה: כרגע אנו מטפלים בפניות דחופות בלבד. אם פנייתך דחופה — נא ציין זאת, אחרת נחזור אליך בהקדם.',
        };
    }
}
