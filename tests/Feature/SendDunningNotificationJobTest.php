<?php

namespace Tests\Feature;

use App\Enums\DunningChannel;
use App\Enums\DunningStatus;
use App\Jobs\SendDunningNotificationJob;
use App\Models\DunningEvent;
use App\Models\Subscription;
use App\Services\SiteAgent\BotNumberRoute;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendDunningNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function makeEvent(): DunningEvent
    {
        $subscription = Subscription::factory()->create();
        $subscription->customer->update(['whatsapp_jid' => '972501234567@c.us']);

        return $subscription->dunningEvents()->create([
            'stage' => 1,
            'channel' => DunningChannel::Whatsapp,
            'template_key' => 'payment_failed',
            'status' => DunningStatus::Queued,
        ]);
    }

    /**
     * דאנינג בוואטסאפ אינו שולח דף תשלום חתום לכתובת שנלמדה.
     *
     * whatsappRecipient() מעדיף את whatsapp_jid, והוא נחתם על הלקוח ממי שכתב
     * לתמיכה ראשון והותאם אליו — כלומר עובד או סוכנות. דף תשלום חתום שנשלח
     * לשם הוא טופס התשלום של עסק שנמסר למי שבמקרה ענה בשרשור תמיכה.
     */
    public function test_a_whatsapp_dunning_notice_does_not_carry_a_signed_card_page_to_a_learned_address(): void
    {
        $event = $this->makeEvent();
        // הטלפון שברשומת הלקוח שונה מה-JID שנלמד.
        $event->subscription->customer->update(['phone' => '0509999999']);

        $body = null;

        $this->mock(WahaClient::class, function ($mock) use (&$body) {
            $mock->shouldReceive('sendMessage')->once()
                ->andReturnUsing(function ($to, $text) use (&$body) {
                    $body = $text;

                    return [];
                });
        });

        (new SendDunningNotificationJob($event->id))->handle(app(WahaClient::class), app(BotNumberRoute::class));

        $this->assertNotNull($body);
        $this->assertStringNotContainsString('update-card', (string) $body,
            'דף התשלום החתום נשלח לכתובת שלא הוצהרה ברשומת הלקוח.');
        $this->assertStringContainsString(route('portal.login'), (string) $body);
    }

    public function test_transient_send_failure_keeps_the_event_queued_for_retry(): void
    {
        $event = $this->makeEvent();

        $this->mock(WahaClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->once()->andThrow(new \RuntimeException('WAHA down'));
        });

        try {
            (new SendDunningNotificationJob($event->id))->handle(app(WahaClient::class), app(BotNumberRoute::class));
            $this->fail('Expected the send failure to bubble up for queue retry.');
        } catch (\RuntimeException) {
            // Expected — the queue retries with backoff.
        }

        // Still Queued, so the retry actually re-processes it.
        $this->assertSame(DunningStatus::Queued, $event->fresh()->status);
    }

    public function test_exhausted_retries_mark_the_event_failed(): void
    {
        $event = $this->makeEvent();

        (new SendDunningNotificationJob($event->id))->failed(new \RuntimeException('WAHA down'));

        $this->assertSame(DunningStatus::Failed, $event->fresh()->status);
    }

    public function test_successful_send_marks_the_event_sent(): void
    {
        $event = $this->makeEvent();

        $this->mock(WahaClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->once()->andReturn(['id' => 'wa-1']);
        });

        (new SendDunningNotificationJob($event->id))->handle(app(WahaClient::class), app(BotNumberRoute::class));

        $fresh = $event->fresh();
        $this->assertSame(DunningStatus::Sent, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
    }
}
