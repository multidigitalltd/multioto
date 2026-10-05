<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentReportSchedule;
use App\Models\SiteAgentSubscriber;

/**
 * Reports, from the conversation: "דוח שבועי עכשיו", "כל בוקר בשמונה תשלח לי
 * את המכירות והלידים של אתמול", "תפסיק את הדוח היומי".
 *
 * Ordering a standing report is not a change to the site, so it takes no "כן":
 * it is the owner's own subscription, a sentence undoes it, and nothing on
 * their website moves. What it does do is send messages — and messages are
 * billed — so the answer says how often it will arrive, and the number of
 * standing reports per phone is capped.
 */
class SiteAgentReportTools
{
    public const REPORT_NOW = 'report_now';

    public const SCHEDULE = 'schedule_report';

    public const LIST = 'list_reports';

    public const CANCEL = 'cancel_report';

    public function __construct(private SiteAgentReportBuilder $builder) {}

    public function handles(string $name): bool
    {
        return in_array($name, [self::REPORT_NOW, self::SCHEDULE, self::LIST, self::CANCEL], true);
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public function definitions(): array
    {
        $frequency = ['type' => 'string', 'enum' => SiteAgentReportSchedule::FREQUENCIES];
        $sections = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => SiteAgentReportSchedule::SECTIONS],
            'description' => 'sales = מכירות, orders = הזמנות שממתינות לטיפול, leads = לידים, subscriptions = מנויים. ריק = הכול.'];

        return [
            [
                'name' => self::REPORT_NOW,
                'description' => 'הפקת דוח עכשיו ושליחתו לבעל האתר כפי שהוא: daily = אתמול, weekly = 7 הימים שהסתיימו אתמול, monthly = החודש הקודם. '
                    .'לשאלה על "היום עד עכשיו" — השתמשו ב-sales_report עם days=1.',
                'input_schema' => ['type' => 'object', 'properties' => ['frequency' => $frequency, 'sections' => $sections], 'required' => ['frequency']],
            ],
            [
                'name' => self::SCHEDULE,
                'description' => 'הזמנת דוח קבוע שיישלח אוטומטית: frequency, time (HH:MM, ברירת מחדל 08:00), weekday לדוח שבועי (0=ראשון … 6=שבת, ברירת מחדל ראשון), sections. '
                    .'דוח חודשי נשלח ב-1 לחודש על החודש הקודם.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'frequency' => $frequency,
                    'time' => ['type' => 'string'],
                    'weekday' => ['type' => 'integer'],
                    'sections' => $sections,
                ], 'required' => ['frequency']],
            ],
            [
                'name' => self::LIST,
                'description' => 'הדוחות הקבועים שבעל האתר הזמין: מזהה, תדירות, מה כלול ומתי יישלח הבא.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => self::CANCEL,
                'description' => 'ביטול דוח קבוע לפי id (מתוך list_reports).',
                'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error?: bool, reply?: string}
     */
    public function call(SiteAgentSubscriber $subscriber, Site $site, string $name, array $input): array
    {
        return match ($name) {
            self::REPORT_NOW => $this->now($site, $input),
            self::SCHEDULE => $this->schedule($subscriber, $site, $input),
            self::LIST => $this->list($subscriber),
            default => $this->cancel($subscriber, $input),
        };
    }

    /** @param array<string, mixed> $input */
    private function now(Site $site, array $input): array
    {
        $frequency = $this->frequency($input);

        if ($frequency === null) {
            return ['content' => 'frequency חייב להיות daily, weekly או monthly.', 'is_error' => true];
        }

        $report = $this->builder->build($site, $frequency, $this->sections($input));

        return [
            'content' => 'הדוח נשלח לבעל האתר כפי שהוא. סיים עכשיו בלי טקסט נוסף.',
            'reply' => $report['text'],
        ];
    }

    /** @param array<string, mixed> $input */
    private function schedule(SiteAgentSubscriber $subscriber, Site $site, array $input): array
    {
        $frequency = $this->frequency($input);
        $time = trim((string) ($input['time'] ?? '08:00'));
        $weekday = isset($input['weekday']) ? (int) $input['weekday'] : null;

        if ($frequency === null) {
            return ['content' => 'frequency חייב להיות daily, weekly או monthly.', 'is_error' => true];
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return ['content' => 'time חייב להיות בפורמט HH:MM, למשל 08:00.', 'is_error' => true];
        }

        if ($weekday !== null && ($weekday < 0 || $weekday > 6)) {
            return ['content' => 'weekday חייב להיות בין 0 (ראשון) ל-6 (שבת).', 'is_error' => true];
        }

        $existing = SiteAgentReportSchedule::query()->where('site_agent_subscriber_id', $subscriber->id)->count();

        if ($existing >= SiteAgentReportSchedule::MAX_PER_NUMBER) {
            return ['content' => 'כבר יש '.SiteAgentReportSchedule::MAX_PER_NUMBER.' דוחות קבועים למספר הזה. בטלו אחד קודם (list_reports, cancel_report).', 'is_error' => true];
        }

        $schedule = new SiteAgentReportSchedule([
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $site->id,
            'frequency' => $frequency,
            'send_time' => $time,
            'weekday' => $frequency === SiteAgentReportSchedule::WEEKLY ? ($weekday ?? 0) : null,
            'sections' => $this->sections($input),
        ]);
        $schedule->next_run_at = $schedule->nextRunAfter(now());
        $schedule->save();

        return ['content' => json_encode([
            'scheduled' => true,
            'id' => $schedule->id,
            'when' => $schedule->describe(),
            'first' => $schedule->next_run_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
            'includes' => $schedule->sections,
            'note' => 'כל דוח הוא הודעה שנספרת בחיוב ההודעות החודשי. אמור זאת לבעל האתר בקצרה, ושאפשר לבטל בכל עת.',
        ], JSON_UNESCAPED_UNICODE)];
    }

    private function list(SiteAgentSubscriber $subscriber): array
    {
        $schedules = SiteAgentReportSchedule::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->orderBy('id')
            ->get()
            ->map(fn (SiteAgentReportSchedule $schedule): array => [
                'id' => $schedule->id,
                'when' => $schedule->describe(),
                'includes' => $schedule->sections,
                'next' => $schedule->next_run_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
            ])
            ->all();

        return ['content' => json_encode(['reports' => $schedules], JSON_UNESCAPED_UNICODE)];
    }

    /** @param array<string, mixed> $input */
    private function cancel(SiteAgentSubscriber $subscriber, array $input): array
    {
        // Scoped to this number: an id from somebody else's list deletes nothing.
        $deleted = SiteAgentReportSchedule::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->whereKey((int) ($input['id'] ?? 0))
            ->delete();

        return $deleted > 0
            ? ['content' => 'הדוח בוטל.']
            : ['content' => 'אין דוח כזה. בדקו עם list_reports.', 'is_error' => true];
    }

    /** @param array<string, mixed> $input */
    private function frequency(array $input): ?string
    {
        $frequency = (string) ($input['frequency'] ?? '');

        return in_array($frequency, SiteAgentReportSchedule::FREQUENCIES, true) ? $frequency : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function sections(array $input): array
    {
        $asked = array_values(array_intersect(SiteAgentReportSchedule::SECTIONS, (array) ($input['sections'] ?? [])));

        return $asked !== [] ? $asked : SiteAgentReportSchedule::SECTIONS;
    }
}
