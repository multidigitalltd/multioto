<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\PluginProduct;
use App\Models\PluginRelease;
use App\Services\Licensing\DownloadLink;
use App\Support\LicenseKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * חוזה שרת הרישיונות (docs/license-api.md).
 *
 * בצד השני של החוזה הזה יושבות חנויות של לקוחות שאיננו שולטים בהן ואיננו יכולים
 * לפרוס אליהן קוד. לכן צורת התשובות כאן קפואה: הוספה בטוחה, שינוי לא.
 *
 * שתי אמיתות שנשמרות כאן יותר מכל:
 *
 *  · **תשובה עסקית היא 200.** "פג תוקף", "חריגה ממכסה" ו"מפתח לא מוכר" הן
 *    תשובות ולא תקלות. התוסף מפרש 5xx או ניתוק כתקלת רשת ו*שומר על המצב
 *    הקודם* — כך שחנות אינה מאבדת רישיון בגלל דקה רעה אצלנו. אילו החזרנו קוד
 *    שגיאה על "לא תקף", תקלה זמנית אצלנו הייתה נראית כביטול רישיונות המוני.
 *
 *  · **המפתח אינו נשמר.** נשמר רק ה-HMAC שלו, והוא גם מה שהחיפוש מוצא לפיו.
 */
class LicenseApiTest extends TestCase
{
    use RefreshDatabase;

    private PluginProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['licensing.secret' => 'test-license-secret']);

        $this->product = PluginProduct::create([
            'slug' => 'wc-store-enhancer',
            'name' => 'משפר חנויות ווקומרס',
            'homepage' => 'https://multidigital.co.il/wc-store-enhancer',
        ]);
    }

    /** @return array{0: License, 1: string} */
    private function license(array $attributes = []): array
    {
        return License::issue($attributes + [
            'plugin_product_id' => $this->product->id,
            'sites_limit' => 1,
            'expires_at' => now()->addYear(),
        ]);
    }

    private function call_(string $endpoint, array $body): TestResponse
    {
        return $this->postJson("/api/license/v1/{$endpoint}", $body);
    }

    /*
    | ----------------------------------------------------------------
    | הפעלה
    | ----------------------------------------------------------------
    */

    /** מפתח תקין על אתר חדש — נרשם ומוחזר valid, בצורת התשובה של החוזה. */
    public function test_activation_registers_the_site_and_answers_in_the_agreed_shape(): void
    {
        [$license, $key] = $this->license();

        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il', 'version' => '1.0.0'])
            ->assertOk()
            ->assertJson([
                'status' => 'valid',
                'expires' => $license->expires_at->format('Y-m-d'),
                'sites_limit' => 1,
                'sites_used' => 1,
                'message' => '',
            ]);

        $this->assertSame('shop.co.il', $license->sites()->sole()->site_url);
        $this->assertSame('1.0.0', $license->sites()->sole()->version);
    }

    /**
     * הפעלה חוזרת מאותו אתר אינה תופסת מקום נוסף.
     *
     * זה לא מקרה קצה: העברת אתר בין שרתים, שחזור גיבוי ומעבר ל-HTTPS כולם
     * נראים כהפעלה חוזרת. אילו כל אחד מהם תפס מושב, המכסה הייתה נגמרת מעצמה
     * והלקוח היה פותח פנייה במקום לחדש.
     */
    public function test_reactivating_the_same_site_does_not_take_another_seat(): void
    {
        [$license, $key] = $this->license();

        $this->call_('activate', ['key' => $key, 'site' => 'http://shop.co.il'])->assertOk();
        $this->call_('activate', ['key' => $key, 'site' => 'https://www.shop.co.il/'])
            ->assertOk()
            ->assertJson(['status' => 'valid', 'sites_used' => 1]);

        $this->assertSame(1, $license->sites()->count());
    }

    /** מעבר למכסה — limit, ובקוד 200. */
    public function test_a_second_site_beyond_the_quota_is_refused_with_a_business_answer(): void
    {
        [$license, $key] = $this->license();

        $this->call_('activate', ['key' => $key, 'site' => 'https://one.co.il'])->assertOk();

        $this->call_('activate', ['key' => $key, 'site' => 'https://two.co.il'])
            ->assertOk()
            ->assertJson(['status' => 'limit', 'sites_used' => 1]);

        $this->assertSame(1, $license->sites()->count());
    }

    /** מכסה 0 = ללא הגבלה. */
    public function test_a_zero_quota_means_unlimited(): void
    {
        [$license, $key] = $this->license(['sites_limit' => 0]);

        foreach (['a.co.il', 'b.co.il', 'c.co.il'] as $site) {
            $this->call_('activate', ['key' => $key, 'site' => "https://{$site}"])
                ->assertOk()->assertJson(['status' => 'valid']);
        }

        $this->assertSame(3, $license->sites()->count());
    }

    /** תוקף שעבר — expired, ועם התאריך בהודעה. */
    public function test_an_expired_licence_says_so_and_stays_a_200(): void
    {
        [, $key] = $this->license(['expires_at' => now()->subDay()]);

        $response = $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()
            ->assertJson(['status' => 'expired']);

        // הודעה בעברית שמוצגת ללקוח כמו שהיא — ולכן היא אומרת גם מה כן ממשיך
        // לעבוד. "פג תוקף" לבדו נקרא כ"התוסף מת".
        $this->assertStringContainsString('ימשיך לעבוד', $response->json('message'));
    }

    /** מפתח לא מוכר, ומפתח מבוטל — שניהם invalid, ובאותה תשובה. */
    public function test_an_unknown_or_revoked_key_is_invalid(): void
    {
        $this->call_('activate', ['key' => 'AAAA-BBBB-CCCC-DDDD', 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'invalid']);

        [$license, $key] = $this->license();
        $license->update(['status' => License::REVOKED]);

        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'invalid']);
    }

    /** מפתח שהוקלד ברישיות שונות, בלי מקפים ועם רווחים — עדיין עובד. */
    public function test_a_key_typed_loosely_still_works(): void
    {
        [, $key] = $this->license();

        $mangled = ' '.strtolower(str_replace('-', '', $key)).' ';

        $this->call_('activate', ['key' => $mangled, 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'valid']);
    }

    /*
    | ----------------------------------------------------------------
    | בדיקה תקופתית ושחרור
    | ----------------------------------------------------------------
    */

    /** check אינו רושם אתר חדש — הוא בודק בלבד. */
    public function test_the_daily_check_never_registers_a_new_site(): void
    {
        [$license, $key] = $this->license();

        $this->call_('check', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'invalid']);

        $this->assertSame(0, $license->sites()->count());
    }

    /** ועל אתר רשום הוא מעדכן "נראה לאחרונה" ואת הגרסה — כך יודעים מי חי. */
    public function test_the_daily_check_records_that_the_shop_is_alive(): void
    {
        [$license, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il', 'version' => '1.0.0']);

        $this->travel(2)->days();
        $this->call_('check', ['key' => $key, 'site' => 'https://shop.co.il', 'version' => '1.1.0'])
            ->assertOk()->assertJson(['status' => 'valid']);

        $seat = $license->sites()->sole();
        $this->assertSame('1.1.0', $seat->version);
        $this->assertTrue($seat->last_seen_at->isToday());
        $this->assertTrue($license->fresh()->last_checked_at->isToday());
    }

    /** שחרור מפנה את המקום, ומצליח גם על מפתח לא מוכר. */
    public function test_deactivation_frees_the_seat_and_never_fails(): void
    {
        [$license, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);

        $this->call_('deactivate', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'inactive']);

        $this->assertSame(0, $license->sites()->count());

        // הלקוח מוחק את המפתח מקומית בכל מקרה; הכשלת הקריאה רק הייתה נועלת
        // אותו עם מפתח שהוא לא יכול להזיז.
        $this->call_('deactivate', ['key' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ', 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'inactive']);
    }

    /*
    | ----------------------------------------------------------------
    | עדכונים
    | ----------------------------------------------------------------
    */

    private function release(string $version = '1.23.0', bool $current = true): PluginRelease
    {
        return PluginRelease::create([
            'plugin_product_id' => $this->product->id,
            'version' => $version,
            'zip_path' => "plugin-releases/{$version}.zip",
            'changelog' => '<ul><li>תיקון</li></ul>',
            'is_current' => $current,
            'released_at' => now(),
        ]);
    }

    /** רישיון תקף מקבל את פרטי הגרסה, עם קישור הורדה ב-HTTPS. */
    public function test_a_valid_licence_is_told_about_the_current_release(): void
    {
        [, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);
        $this->release();

        $response = $this->call_('update', ['key' => $key, 'site' => 'https://shop.co.il', 'version' => '1.0.0'])
            ->assertOk()
            ->assertJson([
                'version' => '1.23.0',
                'requires' => '6.4',
                'requires_php' => '8.0',
                'tested' => '9.9',
            ]);

        $this->assertStringStartsWith('https://', $response->json('download_url'));
        // המפתח לא מופיע בכתובת ההורדה — קישורים כאלה מגיעים ללוגים, לפניות
        // תמיכה ולצילומי מסך.
        $this->assertStringNotContainsString(str_replace('-', '', $key), $response->json('download_url'));
    }

    /** רישיון שאינו מכסה את האתר — 403, והתוסף לא יציג עדכון. */
    public function test_an_uncovered_site_gets_403_and_no_update(): void
    {
        [, $key] = $this->license(['expires_at' => now()->subDay()]);
        $this->release();

        $this->call_('update', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertStatus(403)
            ->assertJson(['status' => 'expired']);
    }

    /** תוסף בלי גרסה מופצת — אין עדכון, אבל גם אין שקר על הרישיון. */
    public function test_a_product_with_nothing_published_is_not_reported_as_a_licence_problem(): void
    {
        [, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);

        $this->call_('update', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()
            ->assertJson(['status' => 'valid'])
            ->assertJsonMissing(['version' => '1.23.0']);
    }

    /** הפצת גרסה חדשה מורידה את הסימון מהקודמת — תשובה אחת לשאלה "מה להוריד". */
    public function test_publishing_a_release_unpublishes_the_previous_one(): void
    {
        $first = $this->release('1.0.0');
        $second = $this->release('1.1.0');

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertSame('1.1.0', $this->product->currentRelease()->number());
    }

    /*
    | ----------------------------------------------------------------
    | ההורדה
    | ----------------------------------------------------------------
    */

    /** קישור חתום ותקף מגיש את הקובץ. */
    public function test_a_signed_link_serves_the_zip(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('plugin-releases/1.23.0.zip', 'PK-zip-bytes');

        [$license, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);
        $this->release();

        $this->get(DownloadLink::url($license->fresh(), 'https://shop.co.il'))
            ->assertOk()
            ->assertDownload('wc-store-enhancer-1.23.0.zip');
    }

    /** חתימה מזויפת, קישור שפג, ואתר אחר — כולם נדחים באותה תשובה. */
    public function test_every_bad_link_is_refused_the_same_way(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('plugin-releases/1.23.0.zip', 'PK');

        [$license, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);
        $this->release();
        $license = $license->fresh();

        $parameters = DownloadLink::parameters($license, 'https://shop.co.il');

        // חתימה שהומצאה.
        $this->get(route('license.download', ['sig' => str_repeat('a', 64)] + $parameters))->assertStatus(403);

        // אותה חתימה, אתר אחר — קישור שנתפס לא יעבוד מאתר אחר.
        $this->get(route('license.download', ['site' => 'other.co.il'] + $parameters))->assertStatus(403);

        // ואחרי שהתוקף עבר.
        $this->travel(2)->hours();
        $this->get(route('license.download', $parameters))->assertStatus(403);
    }

    /** רישיון שבוטל אחרי שהקישור נוצר — ההורדה נחסמת, גם בתוך שעת התוקף. */
    public function test_a_licence_revoked_after_the_link_was_made_stops_the_download(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('plugin-releases/1.23.0.zip', 'PK');

        [$license, $key] = $this->license();
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);
        $this->release();

        $url = DownloadLink::url($license->fresh(), 'https://shop.co.il');
        $license->update(['status' => License::REVOKED]);

        $this->get($url)->assertStatus(403);
    }

    /*
    | ----------------------------------------------------------------
    | אחסון המפתח
    | ----------------------------------------------------------------
    */

    /** המפתח אינו נשמר בשום עמודה — רק ה-HMAC שלו. */
    public function test_the_key_itself_is_never_stored(): void
    {
        [$license, $key] = $this->license();

        $stored = json_encode($license->fresh()->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($key, $stored);
        $this->assertStringNotContainsString(str_replace('-', '', $key), $stored);
        // מה שכן שמור: ארבע התווים הראשונים, כדי שאפשר יהיה להבחין בין שני
        // רישיונות על המסך ובטלפון. הם חסרי ערך בפני עצמם.
        $this->assertSame(substr($key, 0, 4), $license->key_prefix);
    }

    /** מפתח נקרא בטלפון: בלי התווים שמתבלבלים ביניהם. */
    public function test_a_key_avoids_the_characters_people_misread(): void
    {
        foreach (range(1, 40) as $ignored) {
            $this->assertDoesNotMatchRegularExpression('/[O0I1]/', LicenseKey::generate());
        }

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', LicenseKey::generate());
    }

    /** הנפקה מחדש מבטלת את הקודם — ואומרת זאת בכך שהישן מפסיק לעבוד. */
    public function test_reissuing_a_key_kills_the_old_one(): void
    {
        [$license, $old] = $this->license();
        $this->call_('activate', ['key' => $old, 'site' => 'https://shop.co.il'])->assertOk();

        $new = $license->regenerateKey();

        $this->call_('check', ['key' => $old, 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'invalid']);
        $this->call_('check', ['key' => $new, 'site' => 'https://shop.co.il'])
            ->assertOk()->assertJson(['status' => 'valid']);
    }

    /**
     * רישיון שנקנה לתמיד בלי עדכונים — פעיל, ולעולם לא מקבל גרסה חדשה.
     *
     * זה מוצר, לא תקלה. הלקוח קנה את התוסף והוא שלו; לכן הרישיון מדווח valid
     * ואינו פג לעולם, ופשוט אין עבורו עדכון. תשובת 403 הייתה גורמת לתוסף להציג
     * בעיית רישיון על משהו שעובד בדיוק כפי שנמכר.
     */
    public function test_a_licence_bought_without_updates_stays_valid_and_is_never_offered_one(): void
    {
        [$license, $key] = $this->license(['expires_at' => null, 'includes_updates' => false]);
        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il']);
        $this->release();

        $this->call_('check', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()
            ->assertJson(['status' => 'valid', 'expires' => '']);

        $this->call_('update', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()
            ->assertJson(['status' => 'valid'])
            ->assertJsonMissing(['version' => '1.23.0']);

        $this->assertFalse($license->fresh()->includesUpdates());
    }

    /** רישיון ללא תאריך תפוגה מדווח מחרוזת ריקה — כך החוזה מגדיר "ללא תפוגה". */
    public function test_a_licence_without_an_expiry_reports_an_empty_string(): void
    {
        [, $key] = $this->license(['expires_at' => null, 'customer_id' => Customer::factory()->create()->id]);

        $this->call_('activate', ['key' => $key, 'site' => 'https://shop.co.il'])
            ->assertOk()
            ->assertJson(['status' => 'valid', 'expires' => '']);
    }
}
