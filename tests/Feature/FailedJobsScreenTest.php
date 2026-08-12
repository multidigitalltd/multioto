<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\FailedJobs;
use App\Models\FailedJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * מסך העבודות שנכשלו.
 *
 * כל שורה שם היא משהו שהמערכת יצאה לעשות ולא עשתה, ואף אחת מהן אינה מתקנת את
 * עצמה עם הזמן. ההפניה הקודמת הייתה למסך הטכני של Horizon — שם מחלקה ו-stack
 * trace באנגלית — וזו אינה שפה שמחליטים בה: תשע-עשרה עבודות ישבו שם חודש בזמן
 * שבדיקת הבריאות דיווחה עליהן שוב ושוב.
 *
 * הבדיקות כאן הן על התרגום (מה זו הייתה העבודה ומה נשבר) ועל שתי ההכרעות
 * שהמסך מאפשר.
 */
class FailedJobsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function failure(string $job = 'App\\Jobs\\IssueInvoiceJob', string $exception = 'RuntimeException: לינט לא ענתה'): FailedJob
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $job, 'data' => ['commandName' => $job]]),
            'exception' => $exception."\n#0 /var/www/app/Jobs/Thing.php(21): something()\n#1 {main}",
            'failed_at' => now()->subDays(2),
        ]);

        return FailedJob::query()->orderByDesc('id')->firstOrFail();
    }

    /*
    | ----------------------------------------------------------------
    | התרגום
    | ----------------------------------------------------------------
    */

    /** העבודה מוצגת בעברית, עם המשמעות של אי-ביצועה. */
    public function test_a_failure_is_described_in_words_that_support_a_decision(): void
    {
        $failure = $this->failure();

        $this->assertSame('IssueInvoiceJob', $failure->jobClass());
        $this->assertSame('הנפקת חשבונית', $failure->label());
        $this->assertStringContainsString('חשיפה מול רשויות המס', (string) $failure->meaning());
    }

    /** השגיאה מוצגת בלי ה-stack trace — השורה הראשונה היא מה שקרה. */
    public function test_only_the_first_line_of_the_error_is_shown(): void
    {
        $failure = $this->failure();

        $this->assertSame('RuntimeException: לינט לא ענתה', $failure->shortError());
        $this->assertStringNotContainsString('#0', $failure->shortError());
    }

    /**
     * תקלה זמנית מובחנת משגיאה שתחזור.
     *
     * זו ההבחנה שקובעת אם "נסה שוב" שווה משהו: כשל רשת חולף מצליח בניסיון
     * השני, ושגיאת נתונים תיכשל שוב בדיוק באותו מקום.
     */
    public function test_a_transient_failure_is_told_apart_from_one_that_will_repeat(): void
    {
        $this->assertTrue($this->failure(exception: 'ConnectException: cURL error 28: Operation timed out')->looksTransient());
        $this->assertFalse($this->failure(exception: 'TypeError: Argument #1 must be of type string, array given')->looksTransient());
    }

    /** עבודה שאינה ברשימה מוצגת בשמה הטכני ולא בתיאור מומצא. */
    public function test_an_unknown_job_keeps_its_technical_name(): void
    {
        $failure = $this->failure(job: 'App\\Jobs\\SomethingNobodyMappedJob');

        $this->assertSame('SomethingNobodyMappedJob', $failure->label());
        $this->assertNull($failure->meaning());
    }

    /*
    | ----------------------------------------------------------------
    | המסך וההכרעות
    | ----------------------------------------------------------------
    */

    /** המסך נטען ומציג את מה שנכשל. */
    public function test_the_screen_lists_the_failures(): void
    {
        $this->failure();

        Livewire::test(FailedJobs::class)
            ->assertOk()
            ->assertSee('הנפקת חשבונית')
            ->assertSee('לינט לא ענתה');
    }

    /** מחיקה מסירה את הרשומה. */
    public function test_a_failure_can_be_forgotten(): void
    {
        $failure = $this->failure();

        Livewire::test(FailedJobs::class)
            ->callTableAction('forget', $failure);

        $this->assertSame(0, FailedJob::query()->count());
    }

    /** ומחיקה קבוצתית — כמה בבת אחת. */
    public function test_several_failures_can_be_forgotten_at_once(): void
    {
        $first = $this->failure();
        $second = $this->failure();

        Livewire::test(FailedJobs::class)
            ->callTableBulkAction('forgetSelected', [$first, $second]);

        $this->assertSame(0, FailedJob::query()->count());
    }

    /** התג בתפריט סופר את מה שממתין. */
    public function test_the_badge_counts_what_is_waiting(): void
    {
        $this->assertNull(FailedJobs::getNavigationBadge());

        $this->failure();

        $this->assertSame('1', FailedJobs::getNavigationBadge());
    }

    /** אין כשלים — המסך אומר זאת ולא מציג טבלה ריקה בלי הסבר. */
    public function test_an_empty_list_says_everything_ran(): void
    {
        Livewire::test(FailedJobs::class)
            ->assertOk()
            ->assertSee('אין עבודות שנכשלו');
    }
}
