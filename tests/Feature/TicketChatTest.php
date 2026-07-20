<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Jobs\IngestWhatsappMessageJob;
use App\Jobs\SendTicketNotificationJob;
use App\Jobs\SendTicketReplyJob;
use App\Models\CannedResponse;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class TicketChatTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(): Ticket
    {
        $customer = Customer::factory()->create(['name' => 'דנה לוי']);

        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'האתר לא נטען',
            'status' => TicketStatus::Open,
            'external_thread_ref' => '972501234567@c.us',
        ]);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Whatsapp,
            'body' => 'האתר שלי לא נטען כבר שעה',
            'author' => MessageAuthor::Customer,
        ]);

        return $ticket;
    }

    public function test_the_chat_page_shows_the_conversation(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertSee('דנה לוי')
            ->assertSee('האתר שלי לא נטען כבר שעה');
    }

    public function test_ai_drafts_show_in_the_recommendation_panel_not_the_timeline(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $draft = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::InternalNote,
            'body' => "🤖 טיוטת תשובה — בדקו לפני שליחה:\n\nשלום דנה, האתר עלה מחדש ותקין.",
            'author' => MessageAuthor::Ai,
        ]);

        $component = Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertSee('המלצת הסוכן')
            ->assertSee('האתר עלה מחדש ותקין');

        // The draft is out of the conversation timeline, in its own panel.
        $this->assertTrue($component->instance()->messages->doesntContain(fn ($m): bool => $m->id === $draft->id));
        $this->assertTrue($component->instance()->aiDrafts->contains(fn ($m): bool => $m->id === $draft->id));
    }

    public function test_dismissing_an_ai_draft_removes_it(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $draft = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::InternalNote,
            'body' => "🤖 טיוטת תשובה:\n\nטקסט כלשהו",
            'author' => MessageAuthor::Ai,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->call('dismissDraft', $draft->id);

        $this->assertDatabaseMissing('ticket_messages', ['id' => $draft->id]);
    }

    public function test_the_chat_renders_the_sanitized_rich_html_of_an_email(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'שורה מודגשת',
            'body_html' => '<p>שורה <strong>מודגשת</strong></p>',
            'author' => MessageAuthor::Customer,
        ]);

        // The rich markup renders as HTML (assertSeeHtml does not escape).
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertSeeHtml('<strong>מודגשת</strong>');
    }

    public function test_legacy_or_malformed_body_html_is_sanitised_at_render(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        // A legacy row stored raw, malformed email HTML (unclosed tags, <font>,
        // a script). Rendered as-is it would corrupt the component's DOM and
        // break the reply editor; it must be balanced and stripped at render.
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'שלום יש בעיה',
            'body_html' => '<div><p>שלום<div>יש <b>בעיה</b><script>alert(1)</script><font>x</font>',
            'author' => MessageAuthor::Customer,
        ]);

        $safe = strtolower((string) $message->safeBodyHtml());
        $this->assertNotSame('', $safe);
        // Dangerous markup is stripped…
        $this->assertStringNotContainsString('<script', $safe);
        $this->assertStringContainsString('בעיה', $message->safeBodyHtml());
        // …and — the property that actually fixes the editor — the tags are
        // balanced: the unclosed <div>/<p> in the input come out matched, so the
        // browser can't re-parent the component and break Livewire.
        $this->assertSame(substr_count($safe, '<div'), substr_count($safe, '</div>'));
        $this->assertSame(substr_count($safe, '<p'), substr_count($safe, '</p>'));
        // Idempotent: re-sanitising already-safe HTML changes nothing.
        $this->assertSame(
            $message->safeBodyHtml(),
            (new TicketMessage(['body_html' => $message->safeBodyHtml()]))->safeBodyHtml(),
        );

        // The page still renders, with the script neutralised.
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertOk()
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_silent_close_closes_the_ticket_without_notifying_the_customer(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        Livewire::test(ListTickets::class)
            ->callTableBulkAction('closeSilently', [$ticket]);

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        // No resolved (or any) notification is queued for the customer.
        Queue::assertNotPushed(SendTicketNotificationJob::class);
    }

    public function test_a_canned_response_is_inserted_into_the_reply_editor_with_placeholders_filled(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket(); // customer "דנה לוי"
        $canned = CannedResponse::create([
            'title' => 'עדכון טיפול',
            'body' => 'שלום {{customer_name}}, בנוגע לפנייה #{{ticket_id}} — הטיפול בעיצומו.',
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->set('replyData.canned', $canned->id)
            // The template text (placeholders filled) lands in the editor…
            ->assertSet('replyData.body', fn ($body): bool => str_contains((string) $body, 'שלום דנה לוי')
                && str_contains((string) $body, '#'.$ticket->id))
            // …and the picker resets so it can be used again.
            ->assertSet('replyData.canned', null);
    }

    public function test_send_reply_creates_an_outbound_message_and_dispatches_delivery(): void
    {
        Queue::fake([SendTicketReplyJob::class]);
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->set('replyData.body', '<p>בדקנו — האתר חזר לעבוד.</p>')
            ->call('sendReply');

        $outbound = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
        $this->assertSame('בדקנו — האתר חזר לעבוד.', $outbound->body);
        $this->assertSame(MessageChannel::Whatsapp, $outbound->channel); // ticket's channel by default
        $this->assertSame(MessageAuthor::Agent, $outbound->author);
        Queue::assertPushed(SendTicketReplyJob::class, fn ($job) => $job->ticketMessageId === $outbound->id);

        // The ball is now with the customer: status moves to "ממתין ללקוח".
        $ticket->refresh();
        $this->assertSame(TicketStatus::Pending, $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
    }

    public function test_a_reply_with_only_an_unsupported_file_warns_and_sends_nothing(): void
    {
        Queue::fake([SendTicketReplyJob::class]);
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        // An executable is rejected by AttachmentStore; with no text, nothing is
        // sent and the agent is warned instead of a file silently vanishing.
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->set('replyChannel', MessageChannel::Email->value)
            ->set('replyFiles', [UploadedFile::fake()->create('malware.exe', 5)])
            ->call('sendReply');

        $this->assertSame(0, $ticket->messages()->where('direction', MessageDirection::Outbound)->count());
        Queue::assertNothingPushed();
    }

    public function test_internal_note_is_saved_but_never_delivered(): void
    {
        Queue::fake([SendTicketReplyJob::class]);
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->set('replyChannel', MessageChannel::InternalNote->value)
            ->set('replyData.body', '<p>ללקוח יש חוב פתוח — לבדוק לפני שדרוג.</p>')
            ->call('sendReply');

        $this->assertSame(1, $ticket->messages()->where('channel', MessageChannel::InternalNote)->count());
        Queue::assertNothingPushed();
    }

    public function test_chatter_in_the_approvals_chat_never_opens_tickets(): void
    {
        config([
            'billing.waha.owner_number' => '972501112222@c.us', // an approvals group/number
            'billing.waha.default_country_code' => '972',
        ]);

        [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', 'grp-1', [
            'payload' => ['id' => 'grp-1', 'from' => '972501112222@c.us', 'body' => 'סבבה, אני על זה'],
        ]);
        IngestWhatsappMessageJob::dispatchSync($event->id);

        $this->assertSame(0, Ticket::count());
        $this->assertNotNull($event->fresh()->processed_at);
    }
}
