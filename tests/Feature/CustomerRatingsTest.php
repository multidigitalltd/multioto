<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Pages\CustomerRatings;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * דירוגי לקוחות: לקרוא מה נכתב, ולבקש ביקורת בגוגל ממי שנתן חמישה.
 *
 * ההערה נשמרה תמיד — והופיעה רק כ-tooltip על עמודה ברשימת הפניות. משוב שנאסף
 * ואי אפשר לקרוא אותו הוא משוב שלא נאסף.
 */
class CustomerRatingsTest extends TestCase
{
    use RefreshDatabase;

    private function ratedTicket(int $rating, ?string $comment = null): Ticket
    {
        $ticket = Ticket::create([
            'customer_id' => Customer::factory()->create(['name' => 'עסק לדוגמה'])->id,
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה על החשבונית',
            'status' => TicketStatus::Resolved,
        ]);

        $ticket->forceFill([
            'csat_rating' => $rating,
            'csat_comment' => $comment,
            'csat_rated_at' => now(),
        ])->save();

        return $ticket;
    }

    /** מה שהלקוח כתב מופיע כטקסט במסך, לא מאחורי ריחוף. */
    public function test_the_screen_shows_what_the_customer_wrote(): void
    {
        $this->actingAs(User::factory()->create());
        $this->ratedTicket(5, 'שירות מעולה, ענו לי תוך רבע שעה');

        Livewire::test(CustomerRatings::class)
            ->assertOk()
            ->assertSee('שירות מעולה, ענו לי תוך רבע שעה')
            ->assertSee('עסק לדוגמה');
    }

    /** דירוג בלי הערה עדיין מופיע — הציון עצמו הוא מידע. */
    public function test_a_rating_without_a_comment_is_still_listed(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ratedTicket(3);

        Livewire::test(CustomerRatings::class)->assertCanSeeTableRecords([$ticket]);
    }

    /** פנייה שלא דורגה אינה שייכת למסך הזה. */
    public function test_an_unrated_ticket_is_not_listed(): void
    {
        $this->actingAs(User::factory()->create());
        $unrated = Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Email, 'subject' => 'לא דורג', 'status' => TicketStatus::Resolved,
        ]);

        Livewire::test(CustomerRatings::class)->assertCanNotSeeTableRecords([$unrated]);
    }

    /** הקישור לגוגל נשמר מהמסך. */
    public function test_the_google_link_is_saved_from_the_screen(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CustomerRatings::class)
            ->callAction('googleLink', ['url' => 'https://g.page/r/abc/review'])
            ->assertHasNoActionErrors();

        $this->assertSame('https://g.page/r/abc/review', Setting::map()['support.csat_google_url'] ?? null);
    }

    /**
     * חמישה כוכבים — ורק חמישה. מי שאמר שהשירות היה מושלם הוא היחיד שהוגן
     * לבקש ממנו מילה פומבית; מי שנתן ארבעה הייתה לו הסתייגות, ולבקש ממנו
     * לפרסם אותה זה לבקש את הביקורת שאיש לא רצה.
     */
    public function test_a_five_star_rater_is_invited_to_google(): void
    {
        config(['billing.support.csat.google_review_url' => 'https://g.page/r/abc/review']);
        $ticket = $this->ratedTicket(5);

        $this->post($this->rateUrl($ticket), ['rating' => 5])
            ->assertOk()
            ->assertSee('דרגו אותנו בגוגל')
            ->assertSee('https://g.page/r/abc/review');
    }

    /** ארבעה כוכבים — תודה בלבד. */
    public function test_a_four_star_rater_is_not_invited(): void
    {
        config(['billing.support.csat.google_review_url' => 'https://g.page/r/abc/review']);
        $ticket = $this->ratedTicket(4);

        $this->post($this->rateUrl($ticket), ['rating' => 4])
            ->assertOk()
            ->assertDontSee('דרגו אותנו בגוגל');
    }

    /** בלי קישור מוגדר — אין כפתור. כפתור שלא מוביל לשום מקום גרוע מכלום. */
    public function test_no_button_without_a_configured_link(): void
    {
        config(['billing.support.csat.google_review_url' => '']);
        $ticket = $this->ratedTicket(5);

        $this->post($this->rateUrl($ticket), ['rating' => 5])
            ->assertOk()
            ->assertDontSee('דרגו אותנו בגוגל');
    }

    private function rateUrl(Ticket $ticket): string
    {
        return URL::temporarySignedRoute('csat.store', now()->addDay(), ['ticket' => $ticket->id]);
    }
}
