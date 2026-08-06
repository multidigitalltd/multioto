<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\Ticket;

/**
 * Renders operator-editable notification templates. A DB row (seeded from
 * DEFAULTS, edited in the panel) overrides the built-in default; a disabled
 * row silences the notification. Placeholders use {{name}} syntax and are
 * substituted with plain text — escaping happens at the output layer (the
 * mail blade e()s the body; WhatsApp is plain text).
 */
class TemplateEngine
{
    /**
     * Built-in professional Hebrew defaults. key => channel => [subject, body].
     * Subjects apply to email only.
     */
    public const DEFAULTS = [
        'ticket.received' => [
            'email' => [
                'subject' => 'קיבלנו את פנייתך — פנייה #{{ticket_id}}',
                'body' => "שלום {{customer_name}},\n\nתודה שפנית אלינו! פנייתך \"{{ticket_subject}}\" התקבלה ונרשמה במערכת (מספר פנייה: #{{ticket_id}}).\nהצוות שלנו כבר בוחן את הנושא ונחזור אליך בהקדם עם עדכון.\n\nבברכה,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} 👋\nקיבלנו את פנייתך (#{{ticket_id}}) ואנחנו כבר על זה. נעדכן אותך כאן ברגע שיש חדש.\nתודה, {{business_name}}",
            ],
        ],
        'customer.welcome' => [
            'email' => [
                'subject' => 'ברוכים הבאים ל-{{business_name}} 🎉',
                'body' => "שלום {{customer_name}},\n\nאיזה כיף שהצטרפת אלינו! פרטי העסק שלך נקלטו במערכת ואנחנו כבר מתחילים לעבוד.\n\nמה עכשיו?\n• צוות המומחים שלנו זמין לכל שאלה — פשוט השב למייל הזה או כתוב לנו בוואטסאפ.\n• עדכונים על השירות, חשבוניות וחידושים יגיעו אליך אוטומטית.\n\nשמחים שבחרת בנו,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} 🎉\nברוכים הבאים ל-{{business_name}}! הפרטים שלך נקלטו ואנחנו כבר בעבודה.\nלכל שאלה — פשוט כתבו לנו כאן ונענה במהירות.",
            ],
        ],
        'domain.renewal' => [
            'email' => [
                'subject' => 'חידוש דומיין {{domain}} — {{business_name}}',
                'body' => "שלום {{customer_name}},\n\nרישום הדומיין {{domain}} עומד לפוג בתאריך {{expiry_date}} (בעוד {{days_left}} ימים).\nכדי שהאתר והמייל ימשיכו לפעול ללא הפרעה, יש לחדש את רישום הדומיין לפני מועד זה.\n\nאם החידוש באחריותכם — נא לטפל בהקדם מול רשם הדומיינים. לכל שאלה או סיוע אנחנו כאן.\n\nתודה,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} 🌐\nרישום הדומיין {{domain}} עומד לפוג בתאריך {{expiry_date}} (בעוד {{days_left}} ימים).\nכדי שהאתר לא ירד — יש לחדש את הרישום לפני מועד זה. לכל שאלה או סיוע אנחנו כאן.\nתודה, {{business_name}}",
            ],
        ],
        'payment.link' => [
            'email' => [
                'subject' => 'בקשת תשלום — {{business_name}}',
                'body' => "שלום {{customer_name}},\n\nלהלן פירוט התשלום המבוקש:\n{{items}}\nסה״כ לתשלום: {{amount}} (כולל מע״מ)\n\n{{payment_options}}\n\nתודה,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} 👋\nפירוט התשלום המבוקש:\n{{items}}\nסה״כ: {{amount}} (כולל מע״מ)\n\n{{payment_options}}\n\nתודה, {{business_name}}",
            ],
        ],
        'payment.reminder' => [
            'email' => [
                'subject' => 'תזכורת לתשלום — {{business_name}}',
                'body' => "שלום {{customer_name}},\n\nתזכורת ידידותית — טרם נקלט תשלום עבור:\n{{items}}\nסה״כ לתשלום: {{amount}} (כולל מע״מ)\n\n{{payment_options}}\n\nאם כבר שילמת — תודה, ואפשר להתעלם מהודעה זו.\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} 👋\nתזכורת — טרם נקלט תשלום עבור:\n{{items}}\nסה״כ: {{amount}} (כולל מע״מ)\n\n{{payment_options}}\n\nאם כבר שילמת — תודה! {{business_name}}",
            ],
        ],
        'card.capture' => [
            'email' => [
                'subject' => 'ברוכים הבאים ל-{{business_name}} — הזנת פרטי תשלום',
                'body' => "היי {{customer_name}},\n\nשמחים לצרף אתכם! כדי להפעיל את המנוי {{plan}} ({{amount}} ₪ לחודש) נותר רק להזין את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח לחלוטין ומופעל על ידי חברת הסליקה — אנחנו לא רואים ולא שומרים את מספר הכרטיס.\n\nתודה,\n{{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "היי {{customer_name}} 👋\nכדי להפעיל את המנוי {{plan}} ({{amount}} ₪ לחודש) נותר רק להזין את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח ומופעל ע״י חברת הסליקה — איננו רואים או שומרים את מספר הכרטיס.\nתודה, {{business_name}}",
            ],
        ],
        // A customer whose charge date arrived with no card on file. Neither
        // of the neighbouring templates fits: "welcome, let's activate" is
        // wrong for someone who has been with us for months, and "we couldn't
        // charge your card" claims an attempt that never happened. Saying the
        // true thing — the date arrived and there is no card — is also the only
        // version the customer can act on without first being confused.
        'card.missing' => [
            'email' => [
                'subject' => 'הגיע מועד החיוב — חסרים פרטי תשלום | {{business_name}}',
                'body' => "היי {{customer_name}},\n\nהגיע מועד החיוב של המנוי {{plan}} ({{amount}} ₪), אך לא שמורים אצלנו פרטי כרטיס אשראי ולכן החיוב לא בוצע.\n\nכדי שהשירות ימשיך לפעול כרגיל, יש להזין את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח לחלוטין ומופעל על ידי חברת הסליקה — אנחנו לא רואים ולא שומרים את מספר הכרטיס.\n\nאם כבר סידרתם את התשלום בדרך אחרת — אפשר להתעלם מההודעה.\n\nתודה,\n{{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "היי {{customer_name}} 👋\nהגיע מועד החיוב של המנוי {{plan}} ({{amount}} ₪), אך לא שמורים אצלנו פרטי כרטיס ולכן החיוב לא בוצע.\n\nכדי שהשירות ימשיך לפעול, יש להזין את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח ומופעל ע״י חברת הסליקה — איננו רואים או שומרים את מספר הכרטיס.\nאם כבר סידרתם את התשלום בדרך אחרת — אפשר להתעלם.\nתודה, {{business_name}}",
            ],
        ],
        'card.expiring' => [
            'email' => [
                'subject' => 'כרטיס האשראי עומד לפוג — עדכון פרטי תשלום | {{business_name}}',
                'body' => "היי {{customer_name}},\n\nכרטיס האשראי השמור אצלנו (מסתיים ב-{{card_last4}}) עומד לפוג בתוקף לפני מועד החיוב הבא של המנוי {{plan}}. כדי שהחיוב יתבצע כרגיל והשירות ימשיך לפעול ללא הפרעה, יש לעדכן את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח לחלוטין ומופעל על ידי חברת הסליקה — אנחנו לא רואים ולא שומרים את מספר הכרטיס.\n\nתודה,\n{{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "היי {{customer_name}} 💳\nכרטיס האשראי השמור אצלנו (מסתיים ב-{{card_last4}}) עומד לפוג לפני החיוב הבא של המנוי {{plan}}. כדי שהחיוב יתבצע כרגיל יש לעדכן את הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח ומופעל ע״י חברת הסליקה — איננו רואים או שומרים את מספר הכרטיס.\nתודה, {{business_name}}",
            ],
        ],
        'card.capture_debt' => [
            'email' => [
                'subject' => 'עדכון פרטי תשלום — נדרש חידוש התשלום',
                'body' => "היי {{customer_name}},\n\nלא הצלחנו לחייב את התשלום עבור {{plan}} ({{amount}} ₪ לחודש), וכדי שהשירות ימשיך לפעול כרגיל יש להזין מחדש את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח לחלוטין ומופעל על ידי חברת הסליקה — אנחנו לא רואים ולא שומרים את מספר הכרטיס.\n\nתודה,\n{{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "היי {{customer_name}} 👋\nלא הצלחנו לחייב את התשלום עבור {{plan}} ({{amount}} ₪ לחודש). כדי שהשירות ימשיך לפעול יש להזין מחדש את פרטי הכרטיס בעמוד המאובטח:\n{{link}}\n\nהעמוד מאובטח ומופעל ע״י חברת הסליקה — איננו רואים או שומרים את מספר הכרטיס.\nתודה, {{business_name}}",
            ],
        ],
        'ticket.resolved' => [
            'email' => [
                'subject' => 'הפנייה שלך טופלה ✓ — פנייה #{{ticket_id}}',
                'body' => "שלום {{customer_name}},\n\nשמחים לעדכן שהטיפול בפנייתך \"{{ticket_subject}}\" הושלם.\nאם משהו עדיין לא כמצופה — פשוט השב להודעה זו והפנייה תיפתח מחדש אוטומטית.\n\nתודה שבחרת בנו,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}}, הפנייה שלך (#{{ticket_id}}) טופלה ✓\nאם צריך עוד משהו — פשוט כתבו לנו כאן.\nתודה, {{business_name}}",
            ],
        ],
        'ticket.reminder' => [
            'email' => [
                'subject' => 'תזכורת — עדיין מחכים לתשובתך בפנייה #{{ticket_id}}',
                'body' => "שלום {{customer_name}},\n\nרצינו להזכיר שאנחנו עדיין ממתינים לתשובתך בפנייה \"{{ticket_subject}}\" (מספר פנייה: #{{ticket_id}}).\nכדי שנוכל להמשיך ולעזור — פשוט השב להודעה זו.\nאם לא נשמע ממך בימים הקרובים, נסגור את הפנייה, ותמיד תוכל לפתוח אותה מחדש בתגובה.\n\nבברכה,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} 👋\nאנחנו עדיין ממתינים לתשובתך בפנייה #{{ticket_id}}. פשוט השב כאן כדי שנמשיך לעזור.\nאם לא נשמע ממך בימים הקרובים נסגור את הפנייה. תודה, {{business_name}}",
            ],
        ],
        'incident.auto_resolved' => [
            'email' => [
                'subject' => 'זיהינו תקלה באתר {{domain}} — וכבר טיפלנו בה ✓',
                'body' => "שלום {{customer_name}},\n\nמערכת הניטור שלנו זיהתה תקלה באתר {{domain}}, הצוות אישר תיקון אוטומטי ({{fix}}) — והאתר חזר לפעול תקין.\nמשך ההשבתה: {{downtime}}.\n\nאין צורך בשום פעולה מצדך — הכול כבר עובד. אם בכל זאת משהו נראה לא כשורה, פשוט השב להודעה זו.\n\nבברכה,\nצוות {{business_name}}",
            ],
            'whatsapp' => [
                'subject' => null,
                'body' => "שלום {{customer_name}} ✅\nזיהינו תקלה באתר {{domain}} וכבר טיפלנו בה ({{fix}}) — האתר חזר לפעול תקין.\nמשך ההשבתה: {{downtime}}.\nאם משהו עדיין נראה לא כשורה — פשוט כתבו לנו כאן.\nצוות {{business_name}}",
            ],
        ],
    ];

    /**
     * Render a template for a channel ('email'|'whatsapp') with the given data.
     * Returns ['subject' => ?string, 'body' => string], or null when the
     * notification is disabled or unknown.
     *
     * @param  array<string, scalar|null>  $data
     * @return array{subject: ?string, body: string}|null
     */
    /**
     * Whether a template can actually send on a channel: it has body text (from
     * an override or a built-in default) and is not explicitly disabled. Lets the
     * UI avoid offering a channel that would silently send nothing.
     */
    public function isEnabled(string $key, string $channel): bool
    {
        $row = NotificationTemplate::where('key', $key)->where('channel', $channel)->first();

        if ($row !== null && ! $row->enabled) {
            return false;
        }

        $body = $row->body ?? (self::DEFAULTS[$key][$channel]['body'] ?? null);

        return filled($body);
    }

    public function render(string $key, string $channel, array $data): ?array
    {
        $row = NotificationTemplate::where('key', $key)->where('channel', $channel)->first();

        if ($row !== null && ! $row->enabled) {
            return null;
        }

        $default = self::DEFAULTS[$key][$channel] ?? null;
        $subject = $row->subject ?? $default['subject'] ?? null;
        $body = $row->body ?? $default['body'] ?? null;

        if (blank($body)) {
            return null;
        }

        return [
            'subject' => $subject !== null ? $this->substitute($subject, $data) : null,
            'body' => $this->substitute($body, $data),
        ];
    }

    /**
     * Standard placeholder data for a ticket notification.
     *
     * @return array<string, scalar|null>
     */
    public function ticketData(Ticket $ticket): array
    {
        return [
            'customer_name' => $ticket->customer?->name ?: 'לקוח יקר',
            'ticket_id' => $ticket->id,
            'ticket_subject' => $ticket->subject,
            'business_name' => config('mail.from.name') ?: config('app.name'),
        ];
    }

    /** @param array<string, scalar|null> $data */
    protected function substitute(string $text, array $data): string
    {
        foreach ($data as $name => $value) {
            $text = str_replace('{{'.$name.'}}', (string) ($value ?? ''), $text);
        }

        // Any placeholder this template's context didn't supply becomes empty —
        // as the editor promises — so a customer never receives a literal
        // {{token}} (e.g. {{items}} left in a card-link message).
        return preg_replace('/\{\{\s*[\w.]+\s*\}\}/', '', $text) ?? $text;
    }
}
