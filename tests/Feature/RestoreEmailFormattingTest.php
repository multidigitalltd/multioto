<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * שחזור עיצוב שאבד בפניות ישנות.
 *
 * ההודעה נשמרת אחרי סינון, ולכן הדגשה שהמסנן של אז הוריד אינה בשורה של ההודעה.
 * המייל עצמו לא אבד — הוא יושב ב-webhook_events כפי שהספק מסר אותו, וזה מה
 * שהופך את זה לבר-שחזור בכלל.
 */
class RestoreEmailFormattingTest extends TestCase
{
    use RefreshDatabase;

    /** בלי --apply הפקודה מדווחת ואינה נוגעת בכלום. */
    public function test_it_reports_without_changing_anything_by_default(): void
    {
        $message = $this->storedMessage('<p>שלום <span style="background-color:yellow">דחוף</span></p>', '<p>שלום דחוף</p>');

        $this->artisan('support:restore-email-formatting')
            ->expectsOutputToContain('הודעות ישתנו')
            ->assertSuccessful();

        $this->assertSame('<p>שלום דחוף</p>', $message->fresh()->body_html);
    }

    /** ועם --apply ההדגשה חוזרת מהמייל המקורי. */
    public function test_it_restores_the_highlight_from_the_original_delivery(): void
    {
        $message = $this->storedMessage('<p>שלום <span style="background-color:yellow">דחוף</span></p>', '<p>שלום דחוף</p>');

        $this->artisan('support:restore-email-formatting', ['--apply' => true])->assertSuccessful();

        $this->assertStringContainsString('<mark>דחוף</mark>', (string) $message->fresh()->body_html);
    }

    /** הודעה שכבר מוצגת נכון אינה נכתבת מחדש. */
    public function test_a_message_that_already_matches_is_left_alone(): void
    {
        $message = $this->storedMessage('<p>שלום</p>', '<p>שלום</p>');
        $before = $message->updated_at;

        $this->artisan('support:restore-email-formatting', ['--apply' => true])
            ->expectsOutputToContain('אין מה לשחזר')
            ->assertSuccessful();

        $this->assertEquals($before, $message->fresh()->updated_at);
    }

    /** הודעה שהמסירה שלה כבר לא ביומן פשוט מדולגת, בלי להיכשל. */
    public function test_a_message_with_no_delivery_on_file_is_skipped(): void
    {
        $message = $this->storedMessage(null, '<p>שלום</p>');

        $this->artisan('support:restore-email-formatting', ['--apply' => true])->assertSuccessful();

        $this->assertSame('<p>שלום</p>', $message->fresh()->body_html);
    }

    /** תשובה שיצאה מאיתנו אינה נוגעת לשחזור הזה. */
    public function test_outbound_messages_are_not_touched(): void
    {
        $message = $this->storedMessage('<p><span style="background:yellow">א</span></p>', '<p>א</p>');
        $message->update(['direction' => MessageDirection::Outbound]);

        $this->artisan('support:restore-email-formatting', ['--apply' => true])->assertSuccessful();

        $this->assertSame('<p>א</p>', $message->fresh()->body_html);
    }

    /** הודעה נכנסת שנשמרה, עם (או בלי) המסירה המקורית ביומן. */
    private function storedMessage(?string $originalHtml, string $storedHtml): TicketMessage
    {
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'נושא', 'status' => TicketStatus::Open,
        ]);

        if ($originalHtml !== null) {
            WebhookEvent::create([
                'source' => WebhookSource::Email,
                'event_type' => 'inbound_message',
                'external_id' => 'msg-1',
                'payload' => ['HtmlBody' => $originalHtml],
            ]);
        }

        return $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'external_message_id' => 'msg-1',
            'body' => 'שלום',
            'body_html' => $storedHtml,
            'author' => MessageAuthor::Customer,
        ]);
    }
}
