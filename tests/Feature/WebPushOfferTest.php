<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ההצעה להפעיל התראות דפדפן.
 *
 * הפיצ'ר לא שווה דבר למי שלא ידע שהוא קיים, ולכן מוצע — פעם אחת, בדפדפן שבו
 * הוא עוד לא מופעל. הבדיקה מי כן ומי לא היא בדפדפן ולא בשרת: מנוי הוא לדפדפן,
 * ומי שמקבל התראות בשולחן העבודה עדיין אינו מקבל אותן בלפטופ שהוא קורא בו כרגע.
 */
class WebPushOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** מוגדר בשרת — ההצעה נטענת לעמוד. */
    public function test_the_offer_is_rendered_when_push_is_configured(): void
    {
        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);

        $this->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertSee('לקבל התראה ברגע שנכנסת פנייה?');
    }

    /** לא מוגדר בשרת — אין מה להציע, ואין באנר. */
    public function test_nothing_is_offered_when_push_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $this->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertDontSee('לקבל התראה ברגע שנכנסת פנייה?');
    }

    /** להצעה יש דרך לומר "לא" — הצעה שחוזרת כל בוקר אינה הצעה. */
    public function test_the_offer_can_be_dismissed(): void
    {
        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);

        $this->get(route('filament.admin.pages.dashboard'))
            ->assertSee('לא עכשיו')
            ->assertSee('multioto-push-offer-dismissed', false);
    }

    /** דפדפן שכבר חוסם התראות לא מקבל הצעה שאין בה תועלת. */
    public function test_a_browser_that_already_denied_is_not_asked(): void
    {
        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);

        $this->get(route('filament.admin.pages.dashboard'))
            ->assertSee("permission() === 'denied'", false);
    }
}
