<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
use App\Models\Customer;
use App\Models\License;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Services\Licensing\GithubReleases;
use App\Services\Licensing\LicenseSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * גרסאות מגיעות מ-GitHub, ורישיונות נמכרים.
 *
 * שני הדברים שהופכים את זה לעסק ולא לטבלה: הפצת גרסה היא תיוג ב-GitHub ולא
 * גרירת ZIP לטופס, ומכירה פותחת את הגבייה שתמשיך לגבות.
 */
class PluginReleaseSyncTest extends TestCase
{
    use RefreshDatabase;

    private PluginProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['licensing.secret' => 'test-license-secret']);
        Storage::fake('local');

        $this->product = PluginProduct::create([
            'slug' => 'wc-store-enhancer',
            'name' => 'משפר חנויות',
            'github_repo' => 'multidigitalltd/wc-store-enhancer',
        ]);
    }

    /** @param list<array<string, mixed>> $releases */
    private function fakeGithub(array $releases, string $zip = 'PK-built-zip'): void
    {
        Http::fake([
            '*/releases*' => Http::response($releases),
            '*' => Http::response($zip),
        ]);
    }

    private function release(array $overrides = []): array
    {
        return $overrides + [
            'tag_name' => 'v1.2.0',
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-08-01T10:00:00Z',
            'body' => "- תיקון בחירת וריאציה\n- שיפור מהירות",
            'zipball_url' => 'https://api.github.com/repos/x/y/zipball/v1.2.0',
            'assets' => [[
                'name' => 'wc-store-enhancer.zip',
                'url' => 'https://api.github.com/repos/x/y/releases/assets/1',
            ]],
        ];
    }

    /*
    | ----------------------------------------------------------------
    | קליטת גרסאות
    | ----------------------------------------------------------------
    */

    /** Release עם ZIP מצורף נקלט, עם הגרסה, ה-changelog והקובץ. */
    public function test_a_release_with_an_attached_zip_is_imported(): void
    {
        $this->fakeGithub([$this->release()]);

        $result = app(GithubReleases::class)->sync($this->product);

        $this->assertTrue($result['ok']);
        $this->assertSame(['1.2.0'], $result['imported']);

        $release = $this->product->releases()->sole();
        $this->assertSame('1.2.0', $release->version);
        $this->assertStringContainsString('תיקון בחירת וריאציה', $release->changelog);
        $this->assertSame('github', $release->source);
        Storage::disk('local')->assertExists($release->zip_path);
    }

    /**
     * גרסה שנקלטה אינה מופצת מעצמה.
     *
     * שליחת בילד לכל החנויות היא החלטה שמישהו מקבל מול מסך שאומר מה היא עולה —
     * אותו כלל בדיוק שחל על עדכוני תוספים באתרים מנוהלים.
     */
    public function test_an_imported_release_is_not_distributed_by_itself(): void
    {
        $this->fakeGithub([$this->release()]);

        app(GithubReleases::class)->sync($this->product);

        $this->assertFalse($this->product->releases()->sole()->is_current);
        $this->assertNull($this->product->fresh()->currentRelease());
    }

    /** אותה גרסה לא נקלטת פעמיים. */
    public function test_the_same_version_is_never_imported_twice(): void
    {
        $this->fakeGithub([$this->release()]);

        app(GithubReleases::class)->sync($this->product);
        $second = app(GithubReleases::class)->sync($this->product);

        $this->assertSame([], $second['imported']);
        $this->assertSame(1, $this->product->releases()->count());
    }

    /** טיוטה ו-pre-release אינן נקלטות — הן פורסמו במפורש כ"לא לכולם". */
    public function test_drafts_and_prereleases_are_left_alone(): void
    {
        $this->fakeGithub([
            $this->release(['tag_name' => 'v2.0.0', 'draft' => true]),
            $this->release(['tag_name' => 'v1.9.0', 'prerelease' => true]),
        ]);

        app(GithubReleases::class)->sync($this->product);

        $this->assertSame(0, $this->product->releases()->count());
    }

    /**
     * Release בלי ZIP מצורף — נדחה ואומר מה לעשות, ולא נארז מהקוד בשקט.
     *
     * ארכיון הקוד של GitHub עוטף הכל בתיקייה בשם המאגר והגרסה. וורדפרס היה
     * מתקין את התוסף לתיקייה שמשתנה בכל גרסה, ומאבד אותו: העדכון הבא היה
     * מתקין עותק שני לצד הראשון. בנוסף, המאגר אינו התוסף — יש בו בדיקות
     * וקובצי בנייה שאין להם מה לחפש בחנות של לקוח.
     */
    public function test_a_release_without_a_built_zip_is_refused_with_an_explanation(): void
    {
        $this->fakeGithub([$this->release(['assets' => []])]);

        $result = app(GithubReleases::class)->sync($this->product);

        $this->assertSame([], $result['imported']);
        $this->assertStringContainsString('לא צורף קובץ ZIP', $result['skipped']['1.2.0']);
        $this->assertStringContainsString('לא צורף קובץ ZIP', (string) $this->product->fresh()->github_error);
    }

    /** ומי שביקש במפורש לארוז מהקוד — מקבל את זה, ארוז תחת שם התוסף. */
    public function test_packing_from_source_puts_everything_under_the_plugin_slug(): void
    {
        $zipball = $this->sourceZipball();
        Http::fake([
            '*/releases?*' => Http::response([$this->release(['assets' => []])]),
            '*/releases*' => Http::response([$this->release(['assets' => []])]),
            '*' => Http::response($zipball),
        ]);

        $this->product->update(['pack_from_source' => true]);

        $result = app(GithubReleases::class)->sync($this->product);

        $this->assertSame(['1.2.0'], $result['imported']);

        $stored = Storage::disk('local')->path($this->product->releases()->sole()->zip_path);
        $archive = new \ZipArchive;
        $archive->open($stored);

        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = $archive->getNameIndex($i);
        }
        $archive->close();

        // תחת שם התוסף, לא תחת שם המאגר-והגרסה.
        $this->assertContains('wc-store-enhancer/plugin.php', $names);
        // ובלי הרעש של המאגר.
        $this->assertNotContains('wc-store-enhancer/.github/workflows/build.yml', $names);
    }

    /** בלי מאגר מוגדר — נאמר, ולא מנוסה חיבור. */
    public function test_a_product_without_a_repository_says_so(): void
    {
        Http::fake();
        $this->product->update(['github_repo' => null]);

        $result = app(GithubReleases::class)->sync($this->product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('לא הוגדר מאגר', $result['message']);
        Http::assertNothingSent();
    }

    /** תקלה מול GitHub נרשמת על המוצר — אחרת "אין גרסאות חדשות" ו"לא הצלחנו לבדוק" נראים זהים. */
    public function test_a_github_failure_is_recorded_rather_than_looking_like_no_news(): void
    {
        Http::fake(['*' => Http::response('nope', 401)]);

        $result = app(GithubReleases::class)->sync($this->product);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->product->fresh()->github_error);
    }

    /** A zipball shaped the way GitHub builds one. */
    private function sourceZipball(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src');
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString('multidigitalltd-wc-store-enhancer-abc1234/plugin.php', '<?php // Plugin Name: X');
        $archive->addFromString('multidigitalltd-wc-store-enhancer-abc1234/.github/workflows/build.yml', 'name: build');
        $archive->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /*
    | ----------------------------------------------------------------
    | מכירה
    | ----------------------------------------------------------------
    */

    /** מכירת רישיון שנתי פותחת מנוי, מנפיקה מפתח ומקשרת ביניהם. */
    public function test_selling_a_yearly_licence_opens_the_subscription_that_will_renew_it(): void
    {
        $customer = Customer::factory()->create(['email' => 'shop@example.co.il']);
        $this->product->update(['price_agorot' => 24000, 'billing_interval' => 'yearly']);

        $sale = app(LicenseSale::class)->sell(
            product: $this->product,
            customer: $customer,
            sitesLimit: 3,
            interval: BillingInterval::Yearly,
        );

        $subscription = $sale['subscription'];

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame(24000, $subscription->price_agorot_override);
        // נגבה בהרצת הגבייה הקרובה — ולא בתוך הלחיצה של מי שמכר.
        $this->assertTrue($subscription->next_charge_at->isToday());

        $license = $sale['license'];
        $this->assertSame($subscription->id, $license->subscription_id);
        $this->assertSame(3, $license->sites_limit);
        // עובד מיד ולשנה שלמה: הלקוח קנה אותו עכשיו.
        $this->assertSame(now()->addYear()->toDateString(), $license->expires_at->toDateString());
        $this->assertTrue($sale['emailed']);
    }

    /** ומכירה חד-פעמית אינה פותחת מנוי — אין מה לחדש. */
    public function test_a_one_off_sale_opens_no_subscription(): void
    {
        $customer = Customer::factory()->create();

        $sale = app(LicenseSale::class)->sell(
            product: $this->product,
            customer: $customer,
            sitesLimit: 1,
            interval: null,
            priceAgorot: 50000,
        );

        $this->assertNull($sale['subscription']);
        $this->assertSame(0, Subscription::count());
        $this->assertNull($sale['license']->expires_at);
        $this->assertSame(1, License::count());
    }
}
