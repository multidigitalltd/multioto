<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * התראות דפדפן שלא הופעלו בשרת.
 *
 * בלי מפתחות VAPID המתג פשוט נעלם מהפרופיל — ומי שחיכה להתראות שלא הגיעו לא
 * מצא על המסך שום דבר שיסביר למה. הפער לא היה בהפעלה, אלא בשתיקה עליה.
 */
class WebPushSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** בלי מפתחות — נאמר במפורש שזה כבוי, ומה הפקודה שמפעילה. */
    public function test_it_explains_when_push_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        Livewire::test(EditProfile::class)
            ->assertOk()
            ->assertSee('אינן מופעלות בשרת')
            ->assertSee('setup-push.sh');
    }

    /** ועם מפתחות — המתג עצמו, בלי הוראות התקנה. */
    public function test_the_toggle_appears_once_keys_exist(): void
    {
        config([
            'webpush.vapid.public_key' => 'test-public-key',
            'webpush.vapid.private_key' => 'test-private-key',
        ]);

        Livewire::test(EditProfile::class)
            ->assertOk()
            ->assertDontSee('setup-push.sh');
    }
}
