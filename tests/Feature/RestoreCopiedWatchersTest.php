<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * שחזור המכותבים מפניות שנקלטו לפני שקראנו את ה-Cc בכלל.
 *
 * המיילים עצמם מעולם לא אבדו — webhook_events שומר כל מסירה בדיוק כפי שהספק
 * שלח אותה, כולל שורת ה-Cc, לאורך חלון השמירה. הפניות האלה עדיין פתוחות,
 * והאדם שכותב עדיין ממתין.
 */
class RestoreCopiedWatchersTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $payload */
    private function oldMessage(array $payload, string $externalId = 'mid-1'): Ticket
    {
        $ticket = Ticket::create([
            'channel' => TicketChannel::Email,
            'subject' => 'האתר לא עולה',
            'status' => TicketStatus::Open,
            'contact_handle' => 'yossi@customer.co.il',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'האתר לא נטען.',
            'external_message_id' => $externalId,
        ]);

        WebhookEvent::create([
            'source' => WebhookSource::Email,
            'external_id' => $externalId,
            'event_type' => 'inbound',
            'payload' => ['From' => 'yossi@customer.co.il', ...$payload],
        ]);

        return $ticket;
    }

    /** הרצה יבשה מדווחת ואינה כותבת — אחרי ההחלה אנשים מתחילים לקבל מייל. */
    public function test_a_dry_run_reports_without_adding_anybody(): void
    {
        $ticket = $this->oldMessage(['Cc' => 'accountant@external.co.il']);

        $this->artisan('support:restore-copied-watchers')
            ->expectsOutputToContain('accountant@external.co.il')
            ->assertSuccessful();

        $this->assertSame(0, $ticket->watchers()->count());
    }

    /** ועם --apply המכותב חוזר לשיחה. */
    public function test_apply_registers_the_copied_person(): void
    {
        $ticket = $this->oldMessage(['Cc' => 'accountant@external.co.il']);

        $this->artisan('support:restore-copied-watchers', ['--apply' => true])->assertSuccessful();

        $this->assertSame(['accountant@external.co.il'], $ticket->watchers()->pluck('email')->all());
        // Marked as recovered, so it is clear the address came from an old
        // email and not from somebody on the team typing it in today.
        $this->assertStringContainsString('שוחזר', (string) $ticket->watchers()->sole()->added_by);
    }

    /** אותם סינונים חלים — כתובת התמיכה שלנו אינה נכנסת גם בשחזור. */
    public function test_the_same_exclusions_apply_on_a_recovery_run(): void
    {
        config(['billing.email.support_address' => 'support@multi.digital']);
        $ticket = $this->oldMessage(['Cc' => 'support@multi.digital']);

        $this->artisan('support:restore-copied-watchers', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, $ticket->watchers()->count());
    }

    /** מי שכבר רשום אינו נוגע — הרצה חוזרת אינה מייצרת כפילויות. */
    public function test_running_twice_adds_nobody_a_second_time(): void
    {
        $ticket = $this->oldMessage(['Cc' => 'accountant@external.co.il']);

        $this->artisan('support:restore-copied-watchers', ['--apply' => true])->assertSuccessful();
        $this->artisan('support:restore-copied-watchers', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, $ticket->watchers()->count());
    }

    /**
     * והודעה שהמסירה שלה כבר נוקתה פשוט מדולגת.
     *
     * אין מה לשחזר עבורה, ואמירה שלא נמצאו מכותבים עדיפה על העמדת פנים
     * שלפנייה לא היו כאלה.
     */
    public function test_a_message_whose_delivery_was_pruned_is_skipped(): void
    {
        $ticket = $this->oldMessage(['Cc' => 'accountant@external.co.il'], 'mid-gone');
        WebhookEvent::query()->delete();

        $this->artisan('support:restore-copied-watchers', ['--apply' => true])
            ->expectsOutputToContain('לא נמצאו מכותבים חסרים')
            ->assertSuccessful();

        $this->assertSame(0, $ticket->watchers()->count());
    }
}
