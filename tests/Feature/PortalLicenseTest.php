<?php

namespace Tests\Feature;

use App\Mail\LicenseKeyMail;
use App\Models\Customer;
use App\Models\License;
use App\Models\LicenseSite;
use App\Models\PluginProduct;
use App\Models\PluginRelease;
use App\Services\Licensing\LicenseIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * האזור האישי של מי שקנה תוסף.
 *
 * המסך הזה קיים בשביל מצב אחד: האתר שהרישיון הופעל בו כבר לא קיים, ולכן התוסף
 * שאמור לשחרר את המקום אינו יכול. עד שהיה מסך, זה היה מייל תמיכה — ועד שמישהו
 * ענה, לקוח ששילם על שלושה אתרים לא יכול היה להתקין באף אחד.
 */
class PortalLicenseTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private PluginProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['licensing.secret' => 'test-license-secret']);

        $this->customer = Customer::factory()->create(['email' => 'shop@example.co.il']);
        $this->product = PluginProduct::create([
            'slug' => 'wc-store-enhancer',
            'name' => 'משפר חנויות',
        ]);
    }

    private function license(array $overrides = []): License
    {
        [$license] = License::issue($overrides + [
            'plugin_product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'email' => $this->customer->email,
            'sites_limit' => 3,
            'includes_updates' => true,
            'expires_at' => now()->addYear()->toDateString(),
        ]);

        return $license;
    }

    private function signedIn(): self
    {
        return $this->withSession(['portal.customer_id' => $this->customer->id]);
    }

    /*
    | ----------------------------------------------------------------
    | מה רואים
    | ----------------------------------------------------------------
    */

    /** הרישיון, האתרים שהוא פעיל בהם, וכמה מקום נשאר. */
    public function test_the_page_lists_the_licence_its_sites_and_what_is_left(): void
    {
        $license = $this->license();
        $license->sites()->create([
            'site_url' => 'shop.example.co.il',
            'reported_url' => 'https://shop.example.co.il',
            'version' => '1.2.0',
            'activated_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->signedIn()->get(route('portal.licenses'))
            ->assertOk()
            ->assertSee('משפר חנויות')
            ->assertSee('shop.example.co.il')
            ->assertSee('1 מתוך 3 אתרים בשימוש');
    }

    /**
     * רישיון שנקנה לתמיד בלי עדכונים אינו מוצג כ"פג תוקף".
     *
     * זו הטעות שהייתה עולה בפניות: לקוח שקנה את התוסף וקיבל מסך שאומר שהוא פג
     * מבין שקנה משהו שהתקלקל, ולא שקנה משהו שהוא שלו.
     */
    public function test_a_perpetual_licence_is_never_shown_as_expired(): void
    {
        $this->license(['includes_updates' => false, 'expires_at' => null]);

        $this->signedIn()->get(route('portal.licenses'))
            ->assertOk()
            ->assertSee('התוסף שלכם לתמיד ואינו פג', false)
            ->assertDontSee('פג תוקף');
    }

    /** לקוח בלי רישיונות לא מקבל לשונית ריקה. */
    public function test_a_customer_without_licences_is_not_offered_the_tab(): void
    {
        $this->signedIn()->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee(route('portal.licenses'));
    }

    /*
    | ----------------------------------------------------------------
    | שחרור אתר
    | ----------------------------------------------------------------
    */

    /** שחרור אתר מפנה מקום — וזו כל מטרת המסך. */
    public function test_releasing_a_site_frees_the_seat(): void
    {
        $license = $this->license(['sites_limit' => 1]);
        $site = $license->sites()->create([
            'site_url' => 'old.example.co.il', 'reported_url' => 'https://old.example.co.il',
            'activated_at' => now(),
        ]);

        $this->assertFalse($license->hasFreeSeat());

        $this->signedIn()
            ->post(route('portal.licenses.release', ['license' => $license, 'site' => $site]))
            ->assertRedirect(route('portal.licenses'))
            ->assertSessionHas('status');

        $this->assertTrue($license->fresh()->hasFreeSeat());
        $this->assertSame(0, LicenseSite::count());
    }

    /** אי אפשר לשחרר אתר של לקוח אחר. */
    public function test_a_customer_cannot_release_somebody_elses_site(): void
    {
        $stranger = Customer::factory()->create();
        [$theirs] = License::issue([
            'plugin_product_id' => $this->product->id,
            'customer_id' => $stranger->id,
            'sites_limit' => 1,
        ]);
        $site = $theirs->sites()->create([
            'site_url' => 'theirs.example.co.il', 'reported_url' => 'https://theirs.example.co.il',
            'activated_at' => now(),
        ]);

        $this->signedIn()
            ->post(route('portal.licenses.release', ['license' => $theirs, 'site' => $site]))
            ->assertNotFound();

        $this->assertSame(1, LicenseSite::count());
    }

    /** ואי אפשר לשחרר מושב שתלוי ברישיון אחר, גם כששניהם שלי. */
    public function test_a_seat_can_only_be_released_through_its_own_licence(): void
    {
        $mine = $this->license();
        $other = $this->license();
        $site = $other->sites()->create([
            'site_url' => 'other.example.co.il', 'reported_url' => 'https://other.example.co.il',
            'activated_at' => now(),
        ]);

        $this->signedIn()
            ->post(route('portal.licenses.release', ['license' => $mine, 'site' => $site]))
            ->assertNotFound();

        $this->assertSame(1, LicenseSite::count());
    }

    /** אורח לא מגיע לשום מקום. */
    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('portal.licenses'))->assertRedirect(route('portal.login'));
    }

    /*
    | ----------------------------------------------------------------
    | מפתח חדש
    | ----------------------------------------------------------------
    */

    /** "איבדתי את המפתח" מנפיק חדש ושולח אותו — הישן מת. */
    public function test_a_lost_key_is_replaced_and_emailed(): void
    {
        Mail::fake();
        $license = $this->license();
        $before = $license->key_hash;

        $this->signedIn()
            ->post(route('portal.licenses.key', $license))
            ->assertRedirect(route('portal.licenses'));

        $this->assertNotSame($before, $license->fresh()->key_hash);
        Mail::assertQueued(LicenseKeyMail::class);
    }

    /** רישיון מבוטל לא מקבל מפתח חדש דרך הפורטל. */
    public function test_a_revoked_licence_gets_no_new_key(): void
    {
        Mail::fake();
        $license = $this->license();
        $license->update(['status' => License::REVOKED]);
        $before = $license->key_hash;

        $this->signedIn()->post(route('portal.licenses.key', $license))->assertRedirect();

        $this->assertSame($before, $license->fresh()->key_hash);
        Mail::assertNothingQueued();
    }

    /*
    | ----------------------------------------------------------------
    | הורדה חוזרת
    | ----------------------------------------------------------------
    */

    /** רישיון עם עדכונים מקבל את הגרסה הנוכחית. */
    public function test_a_licence_with_updates_downloads_the_current_build(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('releases/current.zip', 'PK-new');

        $delivered = $this->release('1.0.0', 'releases/old.zip', current: false);
        $this->release('2.0.0', 'releases/current.zip', current: true);

        $license = $this->license(['delivered_release_id' => $delivered->id]);

        $this->signedIn()->get(route('portal.licenses.download', $license))
            ->assertOk()
            ->assertDownload('wc-store-enhancer-2.0.0.zip');
    }

    /**
     * ורישיון בלי עדכונים מקבל את מה שקנה — לא את החדש, ולא כלום.
     *
     * שתי החלופות גרועות: הגרסה החדשה נותנת בחינם את מה שאנחנו מוכרים, ושום
     * הורדה משאירה לקוח משלם בלי יכולת להתקין מחדש תוכנה שהיא שלו.
     */
    public function test_a_perpetual_licence_downloads_the_build_it_was_sold_with(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('releases/old.zip', 'PK-old');

        $delivered = $this->release('1.0.0', 'releases/old.zip', current: false);
        $this->release('2.0.0', 'releases/current.zip', current: true);

        $license = $this->license([
            'includes_updates' => false, 'expires_at' => null, 'delivered_release_id' => $delivered->id,
        ]);

        $this->signedIn()->get(route('portal.licenses.download', $license))
            ->assertOk()
            ->assertDownload('wc-store-enhancer-1.0.0.zip');
    }

    /** וכשאין רישום של הגרסה שנמסרה — נאמר, ולא נשלח קובץ שגוי. */
    public function test_an_unknown_delivered_build_says_so_rather_than_guessing(): void
    {
        Storage::fake('local');
        $this->release('2.0.0', 'releases/current.zip', current: true);

        $license = $this->license(['includes_updates' => false, 'expires_at' => null]);

        $this->signedIn()->get(route('portal.licenses.download', $license))->assertNotFound();
    }

    /** הרישיון נשמר עם הגרסה שהופצה בזמן ההנפקה. */
    public function test_issuing_records_the_build_that_was_delivered(): void
    {
        Mail::fake();
        $current = $this->release('3.1.0', 'releases/3.1.0.zip', current: true);

        [$license] = app(LicenseIssuer::class)->issue([
            'plugin_product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'sites_limit' => 1,
        ]);

        $this->assertSame($current->id, $license->delivered_release_id);
    }

    private function release(string $version, string $path, bool $current): PluginRelease
    {
        return PluginRelease::create([
            'plugin_product_id' => $this->product->id,
            'version' => $version,
            'zip_path' => $path,
            'is_current' => $current,
            'released_at' => now(),
            'source' => 'manual',
        ]);
    }
}
