<?php

namespace Tests\Unit;

use App\Services\Calendar\HebrewDate;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HebrewDateTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dates(): array
    {
        return [
            '4 Av 5786' => ['2026-07-18', 'ד׳ באב תשפ״ו'],
            '1 Tishrei 5786 (Rosh Hashana)' => ['2025-09-23', 'א׳ בתשרי תשפ״ו'],
            '15 Nisan 5786 (Pesach)' => ['2026-04-02', 'ט״ו בניסן תשפ״ו'],
            '6 Sivan 5786 (Shavuot)' => ['2026-05-22', 'ו׳ בסיון תשפ״ו'],
            '14 Adar 5785 (common year)' => ['2025-03-14', 'י״ד באדר תשפ״ה'],
            '15 Adar I 5784 (leap year)' => ['2024-02-24', 'ט״ו באדר א׳ תשפ״ד'],
            '14 Adar II 5784 (leap year)' => ['2024-03-24', 'י״ד באדר ב׳ תשפ״ד'],
        ];
    }

    #[DataProvider('dates')]
    public function test_it_renders_the_hebrew_date(string $civil, string $expected): void
    {
        $this->assertSame($expected, HebrewDate::format(Carbon::parse($civil)));
    }

    public function test_the_15_and_16_numerals_avoid_the_divine_name(): void
    {
        // 15 → ט״ו (not י״ה), 16 → ט״ז (not י״ו).
        $this->assertSame('ט״ו בניסן תשפ״ו', HebrewDate::format(Carbon::parse('2026-04-02')));
        $this->assertSame('ט״ז בניסן תשפ״ו', HebrewDate::format(Carbon::parse('2026-04-03')));
    }

    public function test_it_detects_leap_years_by_the_metonic_cycle(): void
    {
        $this->assertTrue(HebrewDate::isLeapYear(5784));   // has Adar I & II
        $this->assertFalse(HebrewDate::isLeapYear(5785));
        $this->assertFalse(HebrewDate::isLeapYear(5786));
        $this->assertTrue(HebrewDate::isLeapYear(5787));
    }

    public function test_it_exposes_the_day_and_month_pieces(): void
    {
        $date = Carbon::parse('2026-07-18'); // 4 Av 5786

        $this->assertSame('ד׳', HebrewDate::day($date));
        $this->assertSame(4, HebrewDate::dayOfMonth($date));
        $this->assertSame('אב', HebrewDate::month($date));
        $this->assertSame('אב תשפ״ו', HebrewDate::monthYear($date));
    }
}
