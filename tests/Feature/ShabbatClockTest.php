<?php

namespace Tests\Feature;

use App\Jobs\FollowUpPendingTicketsJob;
use App\Services\Calendar\ShabbatClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShabbatClockTest extends TestCase
{
    private function clock(): ShabbatClock
    {
        config(['billing.shabbat.block_automations' => true]);

        return new ShabbatClock;
    }

    private function tlv(string $datetime): Carbon
    {
        return Carbon::parse($datetime, 'Asia/Jerusalem');
    }

    public function test_a_regular_weekday_is_not_blocked(): void
    {
        // Wednesday midday.
        $this->assertFalse($this->clock()->isBlocked($this->tlv('2026-07-15 12:00')));
    }

    public function test_shabbat_is_blocked_from_friday_evening_until_the_day_after(): void
    {
        $clock = $this->clock();

        // Friday night (after candle lighting) and Saturday midday: blocked.
        $this->assertTrue($clock->isBlocked($this->tlv('2026-07-17 22:00')));
        $this->assertTrue($clock->isBlocked($this->tlv('2026-07-18 12:00')));

        // Saturday night after havdalah is still held — "wait for the day after".
        $this->assertTrue($clock->isBlocked($this->tlv('2026-07-18 23:30')));

        // Sunday morning: released.
        $this->assertFalse($clock->isBlocked($this->tlv('2026-07-19 09:00')));

        // Friday morning (before candle lighting): not yet blocked.
        $this->assertFalse($clock->isBlocked($this->tlv('2026-07-17 09:00')));
    }

    public function test_held_work_resumes_on_the_morning_after(): void
    {
        $resume = $this->clock()->resumeAt($this->tlv('2026-07-18 12:00'));

        $this->assertNotNull($resume);
        $this->assertSame('2026-07-19 08:00', $resume->clone()->setTimezone('Asia/Jerusalem')->format('Y-m-d H:i'));
    }

    public function test_havdalah_mode_resumes_the_moment_shabbat_goes_out(): void
    {
        config(['billing.shabbat.resume_mode' => 'havdalah']);

        $resume = $this->clock()->resumeAt($this->tlv('2026-07-18 12:00'))
            ?->clone()->setTimezone('Asia/Jerusalem');

        $this->assertNotNull($resume);
        // Motzaei Shabbat (Saturday night), not Sunday morning.
        $this->assertTrue($resume->isSaturday());
        $this->assertGreaterThanOrEqual(19, $resume->hour);
        // And blocking ends there: Saturday night after havdalah is free.
        $this->assertFalse($this->clock()->isBlocked($this->tlv('2026-07-18 23:30')));
    }

    public function test_yom_kippur_is_blocked(): void
    {
        // Tishrei is month 1 in every year — compute Yom Kippur (10 Tishrei) of
        // 5787 and confirm the day is a rest day.
        [$m, $d, $y] = array_map('intval', explode('/', jdtogregorian(jewishtojd(1, 10, 5787))));
        $noon = Carbon::create($y, $m, $d, 12, 0, 0, 'Asia/Jerusalem');

        $this->assertTrue($this->clock()->isBlocked($noon));
        $this->assertSame('יום כיפור', $this->clock()->label($noon));
    }

    public function test_first_day_of_pesach_is_blocked(): void
    {
        // A known real date: first day of Pesach 2026 (15 Nisan 5786).
        $this->assertTrue($this->clock()->isBlocked($this->tlv('2026-04-02 12:00')));
    }

    public function test_a_scheduled_job_re_queues_itself_when_it_runs_during_the_quiet_window(): void
    {
        config(['billing.shabbat.block_automations' => true]);
        Carbon::setTestNow($this->tlv('2026-07-18 12:00')); // Shabbat
        Queue::fake([FollowUpPendingTicketsJob::class]);

        // Even if it slipped past the scheduler gate (queue latency, dispatched
        // just before candle lighting), the job holds itself for the day after.
        (new FollowUpPendingTicketsJob)->handle();

        Queue::assertPushed(FollowUpPendingTicketsJob::class, 1);
        Carbon::setTestNow();
    }

    public function test_the_toggle_disables_blocking(): void
    {
        config(['billing.shabbat.block_automations' => false]);

        $this->assertFalse((new ShabbatClock)->isBlocked($this->tlv('2026-07-18 12:00')));
    }
}
