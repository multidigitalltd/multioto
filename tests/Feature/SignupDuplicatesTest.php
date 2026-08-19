<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ניקוי ההרשמות הכפולות שהטופס הספיק לפתוח לפני שנחסם.
 *
 * הפקודה מוחקת רק כפילות שאיש עדיין לא נגע בה. כפילות שכבר יש עליה מנוי,
 * חשבונית או שיחה עם הלקוח — נשארת, ומדווחת. מיזוג היסטוריות של לקוחות אינו
 * החלטה של פקודה.
 */
class SignupDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    /** Two identical filings of the form, a minute apart. */
    private function pair(): array
    {
        $details = [
            'name' => 'גרנובים יחסי ציבור',
            'contact_name' => 'דנה גרנוב',
            'business_type' => BusinessType::Company,
            'email' => 'dana@example.co.il',
            'phone' => '0501234567',
            'payment_method' => 'standing_order',
        ];

        $first = Customer::create([...$details, 'created_at' => now()->subMinutes(2)]);
        $second = Customer::create([...$details, 'created_at' => now()->subMinute()]);

        return [$first, $second];
    }

    private function followUpTicket(Customer $customer): Ticket
    {
        app(TicketIntake::class)->recordInbound(
            TicketChannel::Manual,
            MessageChannel::InternalNote,
            $customer,
            'לקוח חדש בחר הוראת קבע בנקאית — יש ליצור קשר ולהשלים את הסדר התשלום.',
            externalMessageId: 'signup-payment-'.$customer->id,
            subject: 'השלמת הסדר תשלום — '.$customer->name,
        );

        return $customer->tickets()->sole();
    }

    public function test_it_reports_duplicates_without_touching_anything(): void
    {
        [, $second] = $this->pair();
        $this->followUpTicket($second);

        $this->artisan('signup:duplicates')
            ->expectsOutputToContain('גרנובים יחסי ציבור')
            ->assertSuccessful();

        $this->assertSame(2, Customer::count());
    }

    public function test_clean_removes_the_untouched_copy_and_its_ticket(): void
    {
        [$first, $second] = $this->pair();
        $kept = $this->followUpTicket($first);
        $this->followUpTicket($second);

        $this->assertSame(2, Ticket::count());

        $this->artisan('signup:duplicates --clean')
            ->expectsConfirmation('למחוק 1 לקוחות כפולים (כולל האתרים והפניות שנפתחו איתם)?', 'yes')
            ->assertSuccessful();

        // The first filing and its ticket survive; the repeat is gone entirely
        // — a detached ticket would leave the queue looking the same as before.
        $this->assertSame([$first->id], Customer::pluck('id')->all());
        $this->assertSame([$kept->id], Ticket::pluck('id')->all());
    }

    /**
     * כפילות שכבר יש עליה שיחה — לא נמחקת.
     *
     * זו התשובה הבטוחה: לקוח כפול שמישהו כבר ענה לו מכיל מידע שאין לו עותק,
     * ומחיקה שקטה שלו היא בדיוק סוג הכשל שהפקודה הזו נכתבה כדי לנקות.
     */
    public function test_a_duplicate_that_was_answered_is_reported_and_kept(): void
    {
        [, $second] = $this->pair();
        $ticket = $this->followUpTicket($second);
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'שלחנו את פרטי ההוראה',
            'author' => MessageAuthor::Agent,
        ]);

        $this->artisan('signup:duplicates --clean')
            ->expectsOutputToContain('פנייה עם התכתבות')
            ->assertSuccessful();

        $this->assertSame(2, Customer::count());
    }

    public function test_two_different_customers_are_not_a_duplicate(): void
    {
        Customer::create(['name' => 'א', 'email' => 'a@example.co.il', 'phone' => '0501111111']);
        Customer::create(['name' => 'ב', 'email' => 'b@example.co.il', 'phone' => '0502222222']);

        $this->artisan('signup:duplicates')
            ->expectsOutputToContain('לא נמצאו הרשמות כפולות.')
            ->assertSuccessful();
    }
}
