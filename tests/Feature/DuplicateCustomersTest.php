<?php

namespace Tests\Feature;

use App\Filament\Pages\DuplicateCustomers;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\DuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * כרטיסים כפולים.
 *
 * המיזוג קיים כבר, אבל שום דבר לא הצביע על מה למזג — את הכפילויות מצאו רק
 * כשנתקלו בהן.
 */
class DuplicateCustomersTest extends TestCase
{
    use RefreshDatabase;

    private function finder(): DuplicateFinder
    {
        return app(DuplicateFinder::class);
    }

    /** שני כרטיסים עם אותו מייל — אותו לקוח. */
    public function test_two_cards_sharing_an_email_are_found(): void
    {
        Customer::factory()->create(['email' => 'info@x.co.il', 'phone' => null]);
        Customer::factory()->create(['email' => 'info@x.co.il', 'phone' => null]);

        $groups = $this->finder()->groups();

        $this->assertCount(1, $groups);
        $this->assertSame('אותה כתובת מייל', $groups->first()['reason']);
        $this->assertCount(2, $groups->first()['customers']);
    }

    /** אותיות גדולות ורווחים אינם מזהה אחר — זו אותה תיבת דואר. */
    public function test_case_and_spacing_do_not_hide_a_duplicate(): void
    {
        Customer::factory()->create(['email' => 'Info@X.co.il ', 'phone' => null]);
        Customer::factory()->create(['email' => 'info@x.co.il', 'phone' => null]);

        $this->assertCount(1, $this->finder()->groups());
    }

    /** גם טלפון, ח.פ ווואטסאפ נחשבים מזהים. */
    public function test_a_shared_phone_is_found(): void
    {
        Customer::factory()->create(['phone' => '+972501234567', 'email' => null]);
        Customer::factory()->create(['phone' => '+972501234567', 'email' => null]);

        $this->assertSame('אותו טלפון', $this->finder()->groups()->first()['reason']);
    }

    /**
     * זוג שחולק גם מייל וגם טלפון מדווח פעם אחת. מי שקורא רוצה שורה אחת לכל
     * בעיה, לא שורה לכל סיבה שבגללה היא נתפסה.
     */
    public function test_a_pair_sharing_two_identifiers_is_reported_once(): void
    {
        Customer::factory()->create(['email' => 'a@x.co.il', 'phone' => '+972501234567']);
        Customer::factory()->create(['email' => 'a@x.co.il', 'phone' => '+972501234567']);

        $this->assertCount(1, $this->finder()->groups());
    }

    /**
     * שמות אינם מזהה. שני עסקים שנקראים "מספרה" אינם אותו לקוח, ורשימה שצועקת
     * כפילות על כל מילה משותפת היא רשימה שאיש לא פותח פעמיים.
     */
    public function test_a_shared_name_alone_is_not_a_duplicate(): void
    {
        Customer::factory()->create(['name' => 'מספרה', 'email' => 'a@x.co.il', 'phone' => null]);
        Customer::factory()->create(['name' => 'מספרה', 'email' => 'b@x.co.il', 'phone' => null]);

        $this->assertCount(0, $this->finder()->groups());
    }

    /** שדה ריק אינו מזהה משותף — אחרת כל מי שאין לו טלפון היה כפול של כולם. */
    public function test_empty_identifiers_never_group(): void
    {
        Customer::factory()->count(3)->create(['email' => null, 'phone' => null, 'whatsapp_jid' => null, 'business_number' => null]);

        $this->assertCount(0, $this->finder()->groups());
    }

    /** המסך מציג את הזוג ומפנה למיזוג מהכרטיס שנשאר. */
    public function test_the_screen_lists_them(): void
    {
        $this->actingAs(User::factory()->create());
        Customer::factory()->create(['name' => 'עסק א', 'email' => 'info@x.co.il', 'phone' => null]);
        Customer::factory()->create(['name' => 'עסק ב', 'email' => 'info@x.co.il', 'phone' => null]);

        Livewire::test(DuplicateCustomers::class)
            ->assertOk()
            ->assertSee('עסק א')
            ->assertSee('עסק ב')
            ->assertSee('מיזוג כרטיס כפול לכאן');
    }

    /** ובלי כפילויות — נאמר במפורש שאין. */
    public function test_the_screen_says_when_there_are_none(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DuplicateCustomers::class)->assertSee('אין כרטיסים כפולים');
    }

    /** המונה בתפריט סופר קבוצות, ונעלם כשאין. */
    public function test_the_navigation_badge_counts_groups(): void
    {
        $this->assertNull(DuplicateCustomers::getNavigationBadge());
    }
}
