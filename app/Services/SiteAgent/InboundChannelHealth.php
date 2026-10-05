<?php

namespace App\Services\SiteAgent;

use App\Enums\WebhookSource;
use App\Models\Setting;
use App\Models\SiteAgentSubscriber;
use App\Models\WebhookEvent;
use App\Support\WebhookDeliveries;
use App\Support\WebhookRejections;
use Illuminate\Support\Carbon;

/**
 * האם הודעה של לקוח בכלל מגיעה אלינו.
 *
 * זה הכישלון היחיד במוצר שאינו מייצר שגיאה בשום מקום: הלקוח כותב, לא קורה כלום,
 * ואין שורה באף יומן. מבחינת המערכת פשוט לא נשלחה הודעה.
 *
 * ההבחנה היא כל הערך, כי לשתי השתיקות שנראות זהות מבחוץ יש תיקונים שונים לגמרי:
 *
 *  - **מסירות נדחות** — סוד האפליקציה כאן אינו זהה לזה שבמטא. שדה אחד, בפאנל.
 *  - **מעולם לא הגיע דבר** — שום דבר לא הגיע עד הדלת. הבעיה אצל מטא.
 *
 * סוד האפליקציה הוא גם הערך היחיד ששום דבר אחר אינו מאמת: טוקן האימות מוכח
 * בלחיצת Verify של מטא, המספר והטוקן בשליחה הראשונה — והסוד רק במסירה נכנסת
 * אמיתית. כלומר הוא הכי סביר להיות שגוי בזמן שהכול נראה תקין.
 *
 * הקריאה יושבת במקום אחד כדי שהמסך בפאנל והניטור השעתי יגידו תמיד את אותו דבר.
 * מסך שעונה "תקין" בזמן שההתראה אומרת "דחוף" הוא מסך שלא בודקים יותר.
 *
 * שני דברים שהיומן לבדו אינו יכול לענות עליהם, ושניהם מחזירים בדיוק את ההתראה
 * השקרית שהמחלקה הזאת אמורה למנוע:
 *
 *  1. **יומן ה-webhooks נגזם** (WEBHOOK_RETENTION_DAYS, 60 יום כברירת מחדל,
 *     וניתן לקצר). לכן "אין רשומה" אינו "מעולם לא הגיע", וגם לא "הדחייה היא
 *     האירוע האחרון": חלון שקצר מזיכרון הדחיות היה הופך דחייה ישנה שקדמה
 *     למסירה תקינה ל"דחייה עכשווית". המסירה האחרונה נשמרת לכן כתאריך, בשורה
 *     ששום דבר אינו גוזם, והדחייה נמדדת מולו.
 *  2. **המספר הוחלף.** כל ההיסטוריה מתארת את המספר הקודם, ומספר חדש שמעולם לא
 *     קיבל דבר היה מדווח "תקין" לנצח — כלומר דווקא השתיקה שהניטור קיים בשבילה
 *     הייתה נעלמת. לכן הרשומה נושאת את המספר שאליו היא מתייחסת, ומספר אחר
 *     מתחיל היסטוריה חדשה.
 *
 * ויש מצב שלישי שנראה כמו שתיקה ואינו שתיקה כלל: **מטא מוסרת ואנחנו מאמתים,
 * אבל אף הודעה אינה למספר שלנו.** הוובהוק נרשם לפי חשבון הוואטסאפ ולא לפי מספר,
 * כך שחשבון שמחזיק גם קו תמיכה או קו מכירות מעביר את כולם לאותה כתובת — ואנחנו
 * מסננים את מה שאינו שלנו. הערוץ במקרה כזה תקין לחלוטין, והתקלה היא במספר: הוא
 * אינו בחשבון שנרשם, או שמזהה המספר שבפאנל אינו שלו. אמירת "הבעיה אצל מטא"
 * כאן הייתה שולחת לפרסם אפליקציה שכבר פורסמה.
 */
class InboundChannelHealth
{
    /** The counters the inbound controller writes to, rejected and delivered. */
    public const CHANNEL = 'site-agent-whatsapp';

    /**
     * The durable record of what this channel has carried.
     *
     * Stored, not cached, and deliberately absent from SettingsServiceProvider's
     * allow-list: that map is what the settings page may override in config, and
     * this is a recorded fact, not a setting anybody edits.
     *
     * @var string
     */
    private const RECORD_KEY = 'siteagent.inbound_channel';

    public function __construct(private readonly SiteAgentProduct $product) {}

    /**
     * @return array{accepted: ?Carbon, rejected: ?Carbon, delivered: ?Carbon, everCarried: bool, verdict: 'unready'|'rejected'|'ok'|'foreign'|'silent'}
     */
    public function read(): array
    {
        $record = $this->record();

        // A record naming another number means the number was replaced and the
        // new one has no history yet — nothing before this moment describes it.
        // An ABSENT record means the opposite: this installation simply predates
        // the record, and the number may have been in use for months, so its
        // history counts in full.
        $replaced = $this->replaced();
        $since = $replaced ? now() : $this->date($record['since'] ?? null);

        // The last accepted delivery as far as anything still knows: the newest
        // surviving audit row, or the durable high-water mark when that row has
        // since been pruned — and neither one when it describes the number this
        // installation no longer uses.
        $accepted = $this->latest(
            $this->loggedAcceptance($since),
            $replaced ? null : $this->date($record['last_accepted_at'] ?? null),
        );
        $rejected = WebhookRejections::lastAt(self::CHANNEL);
        $delivered = WebhookDeliveries::lastAt(self::CHANNEL);
        $everCarried = $accepted !== null || $this->verifiedByReply($since);

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'delivered' => $delivered,
            'everCarried' => $everCarried,
            'verdict' => match (true) {
                // Half-configured or switched off: the readiness section already
                // says what is missing, and "nothing reached the door, so the
                // problem is not here" would send the admin to Meta to look for
                // a field that is blank on this very screen.
                ! $this->product->ready() => 'unready',

                // A rejection newer than anything we ever accepted — measured
                // against the durable date, so a short audit window cannot turn
                // an old rejection into the current state of the channel.
                $rejected !== null && ($accepted === null || $rejected->gt($accepted)) => 'rejected',

                $everCarried => 'ok',

                // Meta delivers and we verify — but nothing has ever been for
                // our number. The channel is provably fine, so "the problem is
                // at Meta" would send the fixer to publish an app that is
                // already published. The fault is the number itself.
                $delivered !== null => 'foreign',

                default => 'silent',
            },
        ];
    }

    /**
     * Write down what the channel has carried, so pruning cannot un-know it.
     *
     * Called by the hourly watch rather than from read(): once an hour is as
     * precise as this needs to be — the comparisons it feeds are measured in
     * days — and it keeps a settings-screen visit from writing to the database.
     */
    public function observe(): void
    {
        rescue(function (): void {
            $record = $this->record();
            $replaced = $this->replaced();

            // `since` is set only when the number CHANGES, never when the record
            // is first written: a first write must not declare that a number
            // already in use has no past.
            $since = $replaced ? now() : $this->date($record['since'] ?? null);

            $next = [
                'number' => $this->number(),
                'since' => $since?->toIso8601String(),
                'last_accepted_at' => $this->latest(
                    $this->loggedAcceptance($since),
                    $replaced ? null : $this->date($record['last_accepted_at'] ?? null),
                )?->toIso8601String(),
            ];

            if ($next !== $record) {
                Setting::put(self::RECORD_KEY, (string) json_encode($next));
            }
        }, report: false);
    }

    /**
     * The stored record, or an empty one when it is absent or unreadable.
     *
     * @return array{number?: string, since?: ?string, last_accepted_at?: ?string}
     */
    private function record(): array
    {
        return rescue(function (): array {
            $raw = Setting::map()[self::RECORD_KEY] ?? null;
            $decoded = blank($raw) ? null : json_decode((string) $raw, true);

            return is_array($decoded) ? $decoded : [];
        }, [], report: false);
    }

    /**
     * Does the record describe a number we no longer use?
     *
     * A replaced number inherits none of its predecessor's history. Without
     * this, a new number that never receives anything would be reported as
     * working forever on the strength of deliveries to a different number —
     * which is precisely the silence this whole check exists to catch.
     */
    private function replaced(): bool
    {
        $recorded = $this->record()['number'] ?? null;

        return $recorded !== null && $recorded !== $this->number();
    }

    private function number(): string
    {
        return trim((string) config('siteagent.whatsapp.phone_number_id'));
    }

    /**
     * The newest accepted delivery still in the audit log.
     *
     * Bounded below by `$since` so that deliveries to a number we no longer use
     * are not read as proof about the number we do.
     */
    private function loggedAcceptance(?Carbon $since): ?Carbon
    {
        return rescue(
            fn (): ?Carbon => WebhookEvent::query()
                ->where('source', WebhookSource::WhatsappCloud)
                ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
                ->latest('created_at')
                ->value('created_at'),
            null,
            report: false,
        );
    }

    /**
     * The same fact for installations that predate the durable record.
     *
     * An upgrade arrives with the record unwritten and possibly with the audit
     * rows already pruned, so there has to be a second way to recognise a
     * channel that has worked. A verified subscriber is one: `verified_at` is
     * written in one place only, HandleSiteAgentMessageJob, which runs solely
     * from an accepted delivery — somebody replied with their code over
     * WhatsApp, and that reply reached us. Revoked rows count too, since the
     * question is historical.
     *
     * Not sufficient on its own, which is why it is not the record: the model
     * clears `verified_at` whenever the phone is edited (deliberately — proof
     * about one number must not carry to another), so this can go from true
     * back to false.
     */
    private function verifiedByReply(?Carbon $since): bool
    {
        return rescue(
            fn (): bool => SiteAgentSubscriber::query()
                ->whereNotNull('verified_at')
                ->when($since !== null, fn ($query) => $query->where('verified_at', '>=', $since))
                ->exists(),
            false,
            report: false,
        );
    }

    private function latest(?Carbon ...$dates): ?Carbon
    {
        $known = array_filter($dates);

        return $known === [] ? null : max($known);
    }

    private function date(mixed $value): ?Carbon
    {
        return rescue(
            fn (): ?Carbon => filled($value) ? Carbon::parse((string) $value) : null,
            null,
            report: false,
        );
    }
}
