<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Concerns\CachesNavigationBadge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * למה הפאנל נהיה אט יותר כל חודש.
 *
 * שתי סיבות, ושתיהן בלתי נראות עד שיש מספיק נתונים — ולכן זה דווח לא כ"נשבר"
 * אלא כ"נהיה מאוד איטי".
 *
 * **אף מפתח זר לא היה מאונדקס.** `foreignId()->constrained()` יוצר את האילוץ,
 * וב-MySQL המנוע מוסיף אינדקס מאחוריו בשקט — כך שהקוד נראה כמו Laravel רגיל
 * לגמרי. PostgreSQL לא עושה זאת. כל `$customer->subscriptions`, כל
 * `$ticket->messages`, וכל מחיקה של שורת אב שחייבת לבדוק כל ילד — סריקה מלאה.
 *
 * **והתאריכים שלוח הבקרה מסכם עליהם לא היו מאונדקסים.** על `charges` יש אינדקס
 * על `status` לבדו, שמתכנן שאילתות יתעלם ממנו כי רוב השורות הן 'succeeded';
 * הסינון האמיתי הוא `status + charged_at`, ו-`charged_at` לא הופיע באף אינדקס.
 * שני הסכומים שבמסך הבית סרקו כל חיוב שנעשה מעולם, בכל טעינה, לכל משתמש.
 *
 * והתוספת השלישית: Filament שואל כל פריט ניווט על ה-badge שלו בכל render —
 * לא רק טעינת עמוד, אלא כל מיון, כל סינון, כל מעבר עמוד וכל poll של widget.
 * אחד-עשר מסכים נושאים badge, כלומר אחד-עשר COUNT לפני שמשהו שהמשתמש ביקש
 * מוצג בכלל.
 */
class PanelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The index each hot query shape depends on.
     *
     * Written as the query's own shape — equality column first, range column
     * second — because that is the only order a b-tree serves both from, and an
     * index in the wrong order reads as present while doing nothing.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function hotQueryShapes(): array
    {
        return [
            // The two revenue sums on the home screen.
            'charges: status + charged_at' => ['charges', ['status', 'charged_at']],
            // The payment-demands widget, its summary row and the sidebar badge.
            'charges: demand_sent_at' => ['charges', ['demand_sent_at']],
            // Every entitlement check the site agent makes.
            'subscriptions: customer_id' => ['subscriptions', ['customer_id']],
            // One conversation, in order — read on every ticket open and by every
            // team alert.
            'ticket_messages: ticket_id + id' => ['ticket_messages', ['ticket_id', 'id']],
            'tickets: customer_id' => ['tickets', ['customer_id']],
            'sites: customer_id' => ['sites', ['customer_id']],
            'invoices: customer_id' => ['invoices', ['customer_id']],
            // Deleted by date every night by the job whose whole purpose is to
            // keep the table small.
            'webhook_events: created_at' => ['webhook_events', ['created_at']],
            'notification_logs: created_at' => ['notification_logs', ['created_at']],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    #[DataProvider('hotQueryShapes')]
    public function test_a_hot_query_has_an_index_that_leads_with_its_own_columns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "{$table} is missing.");

        $found = collect(Schema::getIndexes($table))
            ->contains(function (array $index) use ($columns): bool {
                // Leading columns, in order: an index on (a, b) serves a query on
                // "a" and on "a + b", and never one on "b" alone.
                return array_slice(array_map('strtolower', $index['columns']), 0, count($columns))
                    === array_map('strtolower', $columns);
            });

        $this->assertTrue($found, sprintf(
            'No index on %s leads with (%s) — that query scans the table.',
            $table, implode(', ', $columns),
        ));
    }

    /**
     * ה-badge נספר פעם בדקה, לא פעם בבקשה.
     *
     * אף אחד מהמספרים האלה אינו חייב להיות מדויק לשנייה: מספר חייבים שגילו דקה
     * הוא אותו מידע למי שקורא אותו, והמסך שמאחורי ה-badge חי בכל מקרה. זה המקום
     * היחיד בפאנל שבו התיישנות אינה עולה דבר וחישוב מחדש עולה הכי הרבה.
     */
    public function test_a_badge_is_counted_once_and_then_served_from_cache(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $badges = fn (): int => collect($this->badgeClasses())
            ->each(fn (string $class) => $class::getNavigationBadge())
            ->count();

        $before = $queries;
        $badges();
        $cold = $queries - $before;

        $before = $queries;
        $badges();
        $warm = $queries - $before;

        // Every badge costs a query the first time and none the second.
        $this->assertGreaterThan(0, $cold, 'No badge ran at all — the measurement is wrong, not the code.');
        $this->assertSame(0, $warm, "The sidebar still costs {$warm} queries on every render.");
    }

    /** וכל אחד-עשר המסכים עוברים דרך אותה מכונה — לא רק אלה שנזכרו. */
    public function test_every_screen_with_a_badge_caches_it(): void
    {
        $uncached = collect($this->badgeClasses())
            ->reject(fn (string $class): bool => in_array(
                CachesNavigationBadge::class,
                class_uses_recursive($class),
                true,
            ))
            ->values()
            ->all();

        $this->assertSame([], $uncached,
            'These badges run a count on every panel render: '.implode(', ', $uncached));
    }

    /**
     * ו-badge שנופל אינו מפיל את התפריט.
     *
     * badge הוא קישוט בכל מסך בפאנל; ספירה שזורקת — טבלה שעדיין לא קיימת דקה
     * אחרי פריסה — לא יכולה לקחת איתה את כל הניווט.
     */
    public function test_a_badge_that_throws_returns_nothing_rather_than_breaking_the_panel(): void
    {
        $screen = new class
        {
            use CachesNavigationBadge;

            public static function badge(): ?string
            {
                return self::cachedBadge(fn (): int => throw new \RuntimeException('no such table'));
            }
        };

        $this->assertNull($screen::badge());
    }

    /**
     * Every panel screen that draws a badge.
     *
     * Discovered rather than listed, so a screen added tomorrow is covered by
     * the test above without anybody remembering to add it here.
     *
     * @return list<class-string>
     */
    private function badgeClasses(): array
    {
        $classes = [];

        foreach (['Resources', 'Pages'] as $group) {
            foreach (glob(app_path("Filament/{$group}/*.php")) as $file) {
                $class = "App\\Filament\\{$group}\\".basename($file, '.php');

                if (class_exists($class)
                    && method_exists($class, 'getNavigationBadge')
                    && (new \ReflectionMethod($class, 'getNavigationBadge'))->getDeclaringClass()->getName() === $class) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
