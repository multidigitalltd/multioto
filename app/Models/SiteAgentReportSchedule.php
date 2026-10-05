<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report an owner ordered from the site agent, sent on a schedule.
 *
 * Times are the panel's local time: "בשמונה בבוקר" means eight o'clock where
 * the owner lives, in summer and in winter alike, so the next run is always
 * worked out from the local calendar rather than by adding hours.
 */
class SiteAgentReportSchedule extends Model
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY];

    /** What a report can contain. */
    public const SECTIONS = ['sales', 'orders', 'leads', 'subscriptions'];

    /** How many standing reports one number may hold — a report is a message, and messages are billed. */
    public const MAX_PER_NUMBER = 5;

    protected $fillable = [
        'site_agent_subscriber_id', 'site_id', 'frequency', 'send_time', 'weekday',
        'sections', 'next_run_at', 'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'weekday' => 'integer',
            'next_run_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(SiteAgentSubscriber::class, 'site_agent_subscriber_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The first send time strictly after $after, in the local calendar.
     *
     * Monthly reports go on the 1st, about the month that just ended; weekly
     * ones on the chosen weekday (Sunday when none was chosen — the Israeli
     * start of the week), about the seven days before it.
     */
    public function nextRunAfter(CarbonInterface $after): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->send_time ?: '08:00'));
        $local = CarbonImmutable::instance($after)->setTimezone(config('app.timezone'));
        $candidate = $local->setTime($hour, $minute);

        $candidate = match ($this->frequency) {
            self::WEEKLY => $this->nextWeekday($candidate, $local),
            self::MONTHLY => $candidate->startOfMonth()->setTime($hour, $minute)->lte($local)
                ? $candidate->addMonthNoOverflow()->startOfMonth()->setTime($hour, $minute)
                : $candidate->startOfMonth()->setTime($hour, $minute),
            default => $candidate->lte($local) ? $candidate->addDay() : $candidate,
        };

        return $candidate;
    }

    /** "יומי בשעה 08:00", for the owner and the panel. */
    public function describe(): string
    {
        $days = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];

        return match ($this->frequency) {
            self::WEEKLY => "שבועי, ביום {$days[$this->weekday ?? 0]} בשעה {$this->send_time}",
            self::MONTHLY => "חודשי, ב־1 לחודש בשעה {$this->send_time}",
            default => "יומי בשעה {$this->send_time}",
        };
    }

    private function nextWeekday(CarbonImmutable $candidate, CarbonImmutable $local): CarbonImmutable
    {
        $target = $this->weekday ?? 0;

        while ($candidate->dayOfWeek !== $target || $candidate->lte($local)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }
}
