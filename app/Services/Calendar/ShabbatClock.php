<?php

namespace App\Services\Calendar;

use Illuminate\Support\Carbon;

/**
 * Knows when the Shabbat / Yom Tov quiet period is in effect for a fixed
 * location (Tel Aviv by default), and until when held automations should wait.
 *
 * The rest period runs from candle lighting (a few minutes before sunset on the
 * eve) through havdalah (nightfall) the next night; consecutive rest days — a
 * Yom Tov adjoining Shabbat, or the two days of Rosh Hashana — merge into one
 * continuous window. Because the owner asked that everything "wait for the day
 * after", the automation pause extends past havdalah to a set time the
 * following morning.
 *
 * Israel practice: the melacha-forbidden days are Shabbat, Rosh Hashana (2),
 * Yom Kippur, the first day of Sukkot, Shmini Atzeret, the first and seventh
 * days of Pesach, and Shavuot. Chol HaMoed is a normal working day.
 */
class ShabbatClock
{
    private const TZ = 'Asia/Jerusalem';

    private float $lat;

    private float $lon;

    private int $candleOffset;

    private int $havdalahOffset;

    private string $resumeTime;

    public function __construct()
    {
        $this->lat = (float) config('billing.shabbat.latitude', 32.0853);
        $this->lon = (float) config('billing.shabbat.longitude', 34.7818);
        $this->candleOffset = (int) config('billing.shabbat.candle_offset_minutes', 18);
        $this->havdalahOffset = (int) config('billing.shabbat.havdalah_offset_minutes', 40);
        $this->resumeTime = (string) config('billing.shabbat.resume_time', '08:00');
    }

    /**
     * Should outward automation be held right now? False when the feature is
     * switched off, so callers can gate unconditionally.
     */
    public function isBlocked(?Carbon $at = null): bool
    {
        if (! config('billing.shabbat.block_automations', true)) {
            return false;
        }

        return $this->haltWindow($at ?? Carbon::now()) !== null;
    }

    /**
     * When held automations may resume (the morning after the rest ends), or
     * null when nothing is being held. Ignores the feature toggle so a caller
     * can compute a delay even while deciding whether to apply it.
     */
    public function resumeAt(?Carbon $at = null): ?Carbon
    {
        return $this->haltWindow($at ?? Carbon::now())['resume'] ?? null;
    }

    /** A short Hebrew label for the current rest ("שבת" / the chag), or null. */
    public function label(?Carbon $at = null): ?string
    {
        return $this->haltWindow($at ?? Carbon::now())['label'] ?? null;
    }

    /**
     * The active pause window covering $at, or null. Shape:
     * [entry, exit, resume, label] — entry = candle lighting, exit = havdalah,
     * resume = the day-after morning automations resume.
     *
     * @return array{entry: Carbon, exit: Carbon, resume: Carbon, label: string}|null
     */
    private function haltWindow(Carbon $at): ?array
    {
        $at = $at->copy()->setTimezone(self::TZ);

        // A window belongs to a rest date whose eve is the day before; check the
        // day before, of, and after $at so evening-of-erev and post-havdalah
        // (still-paused) moments both resolve.
        foreach ([-1, 0, 1] as $delta) {
            $day = $at->copy()->startOfDay()->addDays($delta);

            if (! $this->isRestDate($day)) {
                continue;
            }

            // Grow to the maximal run of consecutive rest dates (chag+Shabbat, RH).
            $first = $day->copy();
            while ($this->isRestDate($first->copy()->subDay())) {
                $first->subDay();
            }
            $last = $day->copy();
            while ($this->isRestDate($last->copy()->addDay())) {
                $last->addDay();
            }

            $entry = $this->sunset($first->copy()->subDay())->subMinutes($this->candleOffset);
            $exit = $this->sunset($last)->addMinutes($this->havdalahOffset);
            $resume = $last->copy()->addDay()->setTimeFromTimeString($this->resumeTime);

            // Paused from candle lighting until the day-after resume time.
            if ($at->betweenIncluded($entry, $resume)) {
                return ['entry' => $entry, 'exit' => $exit, 'resume' => $resume, 'label' => $this->restLabel($first)];
            }
        }

        return null;
    }

    /** Is this civil date a Shabbat or a melacha-forbidden Yom Tov (Israel)? */
    private function isRestDate(Carbon $date): bool
    {
        return $date->isSaturday() || $this->isYomTov($date);
    }

    /**
     * Yom Tov check via the built-in Jewish calendar. PHP's CAL_JEWISH numbers
     * months the same way every year — Tishrei 1 … Nisan 8, Iyar 9, Sivan 10 —
     * so no leap-year adjustment is needed for these holidays (verified against
     * real Pesach/Shavuot dates in both common and leap years).
     */
    private function isYomTov(Carbon $date): bool
    {
        [$month, $day] = $this->jewishMonthDay($date);

        // Tishrei (1): Rosh Hashana 1–2, Yom Kippur 10, Sukkot 15, Shmini Atzeret 22.
        if ($month === 1 && in_array($day, [1, 2, 10, 15, 22], true)) {
            return true;
        }

        // Pesach (Nisan 8) first (15) and seventh (21) day.
        if ($month === 8 && in_array($day, [15, 21], true)) {
            return true;
        }

        // Shavuot (Sivan 10, 6th).
        return $month === 10 && $day === 6;
    }

    /** @return array{0:int,1:int} [jewish month, jewish day] for a civil date. */
    private function jewishMonthDay(Carbon $date): array
    {
        [$month, $day] = array_map(
            'intval',
            explode('/', jdtojewish(gregoriantojd($date->month, $date->day, $date->year)))
        );

        return [$month, $day];
    }

    private function restLabel(Carbon $firstRestDate): string
    {
        if (! $this->isYomTov($firstRestDate)) {
            return 'שבת';
        }

        [$month, $day] = $this->jewishMonthDay($firstRestDate);

        return match (true) {
            $month === 1 && in_array($day, [1, 2], true) => 'ראש השנה',
            $month === 1 && $day === 10 => 'יום כיפור',
            $month === 1 && $day === 15 => 'סוכות',
            $month === 1 && $day === 22 => 'שמיני עצרת',
            $month === 10 => 'שבועות',
            default => 'פסח',
        };
    }

    /** Local sunset for a given date at the configured location. */
    private function sunset(Carbon $date): Carbon
    {
        $noon = $date->copy()->setTimezone(self::TZ)->setTime(12, 0);
        $info = date_sun_info($noon->getTimestamp(), $this->lat, $this->lon);

        return Carbon::createFromTimestamp($info['sunset'], self::TZ);
    }
}
