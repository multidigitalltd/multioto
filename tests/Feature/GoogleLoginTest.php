<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * התחברות עם גוגל — הבדיקות כאן הן בעיקר על מי שלא נכנס.
 *
 * זו כניסה לפאנל שמנהל כסף של לקוחות, ולכל אדם בעולם יש חשבון גוגל. הכניסה
 * הזו מזהה מי אתה; היא אינה מחליטה שמותר לך.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    }

    /** משתמש קיים, כתובת מאומתת — נכנס. */
    public function test_an_existing_user_with_a_verified_address_is_signed_in(): void
    {
        $user = User::factory()->create(['email' => 'team@example.co.il']);

        $this->pretendGoogleReturns('team@example.co.il', verified: true, id: '1234567890');

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('1234567890', $user->fresh()->google_id);
    }

    /**
     * כתובת שאינה רשומה אינה נכנסת, ואינה נפתחת.
     *
     * זו ההחלטה היחידה שחשובה בקובץ הזה. כניסה שפותחת משתמש למי שהתחבר בגוגל
     * היא דלת פתוחה לפאנל.
     */
    public function test_an_unknown_address_is_refused_and_no_user_is_created(): void
    {
        $this->pretendGoogleReturns('stranger@gmail.com', verified: true, id: '999');

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'stranger@gmail.com')->count());
    }

    /** כתובת שגוגל עצמה לא אימתה אינה ראיה לכלום. */
    public function test_an_unverified_address_is_refused(): void
    {
        User::factory()->create(['email' => 'team@example.co.il']);

        $this->pretendGoogleReturns('team@example.co.il', verified: false, id: '1');

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertGuest();
    }

    /**
     * כתובת שכבר מקושרת לחשבון גוגל אחד לא תיכנס דרך חשבון אחר.
     *
     * כתובת מייל אפשר להעביר בין חשבונות; מזהה החשבון אצל גוגל — לא.
     */
    public function test_an_address_linked_to_another_google_account_is_refused(): void
    {
        User::factory()->create(['email' => 'team@example.co.il', 'google_id' => 'first']);

        $this->pretendGoogleReturns('team@example.co.il', verified: true, id: 'second');

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertGuest();
    }

    /** ההגבלה לדומיין, כשהוגדרה, חלה גם על מי שכבר רשום. */
    public function test_the_domain_restriction_is_enforced(): void
    {
        config(['auth.google.allowed_domain' => 'multi-digital.co.il']);
        User::factory()->create(['email' => 'team@example.co.il']);

        $this->pretendGoogleReturns('team@example.co.il', verified: true, id: '1');

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertGuest();
    }

    /** בלי הגדרה, הכפתור לא מופיע ואין לאן ללכת. */
    public function test_google_login_is_inert_until_it_is_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('auth.google.redirect'))->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest();
    }

    /** תשובה מגוגל, בלי לצאת לרשת. */
    private function pretendGoogleReturns(string $email, bool $verified, string $id): void
    {
        $account = (new SocialiteUser)->setRaw(['email_verified' => $verified])
            ->map(['id' => $id, 'email' => $email, 'name' => 'צוות']);

        $provider = \Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($account);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
