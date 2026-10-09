<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\WebhookSource;
use App\Jobs\HandleSiteAgentMessageJob;
use App\Jobs\SendSiteAgentFailureAlertJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentSubscriber;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\SiteAgent\ImageChangePlanner;
use App\Services\SiteAgent\ProductChangePlanner;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentFailureAlerts;
use App\Services\SiteAgent\SiteAgentMessageCap;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Services\SiteAgent\SiteAgentWelcome;
use App\Services\SiteAgent\SiteChoice;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentFailureAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.timezone' => 'Asia/Jerusalem',
            'siteagent.alerts.failure_email' => '',
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.notifications.team_email' => 'general-team@example.test',
        ]);
        Queue::fake([SendSiteAgentFailureAlertJob::class]);
        Mail::fake();
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create(['email' => 'customer@example.test']);
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'example.test']);

        return SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'name' => 'ריקי', 'verified_at' => now(),
        ]);
    }

    private function message(SiteAgentSubscriber $subscriber, string $body, string $role = SiteAgentMessage::USER, ?int $siteId = null): SiteAgentMessage
    {
        return SiteAgentMessage::create([
            'site_agent_subscriber_id' => $subscriber->id, 'site_id' => $siteId ?? $subscriber->site_id,
            'body' => $body, 'role' => $role,
        ]);
    }

    private function dispatch(SiteAgentSubscriber $subscriber, int $event = 1, int $through = 0, string $reply = SiteAgentAssistant::NO_VERIFIED_PROPOSAL, string $request = 'להחליף טקסט בדף הבית'): SendSiteAgentFailureAlertJob
    {
        app(SiteAgentFailureAlerts::class)->dispatch($event, $subscriber, $request, $reply, $through);

        return Queue::pushed(SendSiteAgentFailureAlertJob::class)->last();
    }

    public function test_snapshot_is_scoped_bounded_chronological_and_redacted_before_encrypted_queueing(): void
    {
        User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@example.test']);
        User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.test']);
        $subscriber = $this->subscriber();
        $other = $this->subscriber();
        $this->message($other, 'other subscriber secret');
        $this->message($subscriber, 'other site secret', siteId: $other->site_id);
        for ($i = 0; $i < 45; $i++) {
            $this->message($subscriber, 'history-'.$i);
        }
        $boundary = $this->message($subscriber, "password: do-not-copy\napi_key=secret-key\nסיסמה: hidden-value\nBearer opaque-secret\nhttps://example.test/?token=signed-secret\n<script>alert(1)</script>")->id;
        $this->message($subscriber, 'later concurrent request');
        $job = $this->dispatch($subscriber, through: $boundary, request: str_repeat('א', 5000));

        $this->assertSame(['admin@example.test'], $job->recipients);
        $this->assertSame('database', $job->connection);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        foreach (['other subscriber secret', 'other site secret', 'later concurrent request', 'history-0', 'do-not-copy', 'secret-key', 'hidden-value', 'opaque-secret', 'signed-secret'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $job->bodyText);
        }
        $this->assertStringContainsString('היסטוריה קודמת קוצרה', $job->bodyText);
        $this->assertStringContainsString('הודעה קוצרה', $job->bodyText);
        $this->assertStringContainsString('history-44', $job->bodyText);
        $this->assertLessThan(strpos($job->bodyText, 'history-44'), strpos($job->bodyText, 'history-43'));
        $this->assertStringContainsString('google / gemini-3.1-flash-lite', $job->bodyText);
        $this->assertStringContainsString('Asia/Jerusalem', $job->bodyText);
        $this->assertStringContainsString($subscriber->phone, $job->bodyText);
        $this->assertLessThan(25000, mb_strlen($job->bodyText));
        $this->message($subscriber, 'after queue secret');
        $job->handle();
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            $this->assertStringNotContainsString('after queue secret', $mail->bodyText);
            $this->assertStringNotContainsString('<script>', $mail->render());
            $this->assertStringContainsString('&lt;script&gt;', $mail->render());

            return $mail->hasTo('admin@example.test') && ! $mail->hasTo('customer@example.test') && ! $mail->hasTo('agent@example.test') && ! $mail->hasTo('general-team@example.test');
        });
        Queue::getFacadeRoot()->queue->connection('database')->push($job);
        $payload = json_decode(DB::table('jobs')->latest('id')->value('payload'), true);
        $this->assertStringNotContainsString($subscriber->phone, $payload['data']['command']);
        $this->assertStringContainsString($subscriber->phone, Crypt::decryptString($payload['data']['command']));
    }

    public static function failures(): array
    {
        return [
            ['לא הצלחתי להבין בוודאות מה לשנות, ולכן לא נגעתי בכלום.'],
            ['לא הבנתי מה לשנות. כתבו לי מה תרצו לעדכן באתר.'],
            [SiteAgentAssistant::NO_VERIFIED_PROPOSAL],
            [SiteAgentConversation::NO_PENDING_PROPOSAL],
            ['קיבלתי את התמונה, אבל לא הצלחתי להבין לאן לשים אותה.'],
            [ImageChangePlanner::UNRESOLVED_IMAGE.' היעד שנבחר הוא "מוצר א". איך לתאר את התמונה? אפשר גם לבטל.'],
            [ProductChangePlanner::SEARCH_UNAVAILABLE],
            [ProductChangePlanner::AI_UNAVAILABLE],
            [ImageChangePlanner::SEARCH_UNAVAILABLE],
        ];
    }

    #[DataProvider('failures')]
    public function test_each_known_fallback_alerts_with_current_pair_when_history_is_absent(string $reply): void
    {
        config(['siteagent.alerts.failure_email' => 'chosen-admin@example.test']);
        $job = $this->dispatch($this->subscriber(), reply: $reply);
        $this->assertSame(['chosen-admin@example.test'], $job->recipients);
        $this->assertStringContainsString('להחליף טקסט בדף הבית', $job->bodyText);
        $this->assertStringContainsString($reply, $job->bodyText);
        $this->assertStringContainsString('tableFilters', $job->bodyText);
    }

    public static function ordinaryReplies(): array
    {
        return [
            ['איזה טקסט תרצה להחליף?'], ['בוצע.'], ['אין הרשאה לפעולה הזאת.'], ['לא הצלחתי לקרוא את התמונה. אפשר לשלוח JPG?'],
            ['לא מצאתי יעד חד־משמעי לתמונה. מה שם המוצר או העמוד כפי שהוא מופיע באתר? התמונה והתיאור שכבר מסרתם נשמרו.'],
            ['איך לתאר את התמונה?'], ['לא מצאתי מוצר בשם "חולצה" בחנות. אפשר לכתוב את שם המוצר המדויק או את המק"ט?'],
            ['תשובה חופשית שמזכירה הודעה קודמת: '.ImageChangePlanner::UNRESOLVED_IMAGE],
        ];
    }

    #[DataProvider('ordinaryReplies')]
    public function test_ordinary_replies_do_not_alert(string $reply): void
    {
        config(['siteagent.alerts.failure_email' => 'admin@example.test']);
        app(SiteAgentFailureAlerts::class)->dispatch(1, $this->subscriber(), 'request', $reply, 0);
        Queue::assertNotPushed(SendSiteAgentFailureAlertJob::class);
    }

    public function test_no_admin_recipient_never_falls_back_to_customer_agent_or_general_team_email(): void
    {
        User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.test']);
        app(SiteAgentFailureAlerts::class)->dispatch(1, $this->subscriber(), 'request', SiteAgentAssistant::NO_VERIFIED_PROPOSAL, 0);
        Queue::assertNotPushed(SendSiteAgentFailureAlertJob::class);
    }

    public function test_mail_failure_retries_independently_success_is_deduplicated_and_distinct_failures_are_not_suppressed(): void
    {
        $job = new SendSiteAgentFailureAlertJob(17, ['admin@example.test'], 'safe snapshot');
        $originalMail = Mail::getFacadeRoot();
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP password=private-transport-secret'));
        Mail::shouldReceive('to')->once()->andReturn($pending);
        try {
            $job->handle();
            $this->fail('Mail error must permit a queued retry.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Site-agent failure alert mail delivery failed.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        Mail::swap($originalMail);
        $job->handle();
        $job->handle();
        (new SendSiteAgentFailureAlertJob(18, ['admin@example.test'], 'second failure, same owner'))->handle();
        Mail::assertSent(NotificationMail::class, 2);
    }

    public function test_delivery_job_only_queues_alert_after_success_and_preserves_pre_request_history_boundary(): void
    {
        config(['siteagent.alerts.failure_email' => 'admin@example.test']);
        $subscriber = $this->subscriber();
        $this->message($subscriber, 'previous context');
        $event = $this->handleMessage($subscriber, 'outgoing-message');
        $job = Queue::pushed(SendSiteAgentFailureAlertJob::class)->sole();
        $this->assertStringContainsString('previous context', $job->bodyText);
        $this->assertStringNotContainsString('concurrent later message', $job->bodyText);
        $this->assertNotNull($event->fresh()->processed_at);
        Mail::assertNothingSent();
    }

    public function test_failed_whatsapp_delivery_does_not_queue_an_alert(): void
    {
        config(['siteagent.alerts.failure_email' => 'admin@example.test']);
        $this->handleMessage($this->subscriber(), null);
        Queue::assertNotPushed(SendSiteAgentFailureAlertJob::class);
    }

    public static function recoveryDeliveries(): array
    {
        $cases = [];
        foreach ([ImageChangePlanner::UNRESOLVED_IMAGE.' לאיזה מוצר התכוונתם?', ImageChangePlanner::SEARCH_UNAVAILABLE, ProductChangePlanner::SEARCH_UNAVAILABLE, ProductChangePlanner::AI_UNAVAILABLE] as $reply) {
            $cases[] = [$reply, true];
            $cases[] = [$reply, false];
        }

        return $cases;
    }

    #[DataProvider('recoveryDeliveries')]
    public function test_new_recovery_failures_alert_only_after_the_actual_response_is_accepted(string $reply, bool $delivered): void
    {
        config(['siteagent.alerts.failure_email' => 'admin@example.test']);
        $event = $this->handleMessage($this->subscriber(), $delivered ? 'outgoing-message' : null, $reply);

        $this->assertNotNull($event->fresh()->processed_at);
        if ($delivered) {
            Queue::assertPushed(SendSiteAgentFailureAlertJob::class, fn (SendSiteAgentFailureAlertJob $job): bool => str_contains($job->bodyText, $reply));
        } else {
            Queue::assertNotPushed(SendSiteAgentFailureAlertJob::class);
        }
        Mail::assertNothingSent();
    }

    public function test_diagnostic_history_lookup_failure_does_not_abort_the_owner_request_or_expose_its_exception(): void
    {
        config(['siteagent.alerts.failure_email' => 'admin@example.test']);
        $subscriber = $this->subscriber();
        Log::spy();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'select ') && str_contains($query->sql, 'site_agent_messages')) {
                throw new \RuntimeException('diagnostic-query-secret=password');
            }
        });

        $event = $this->handleMessage($subscriber, 'outgoing-message');

        $this->assertNotNull($event->fresh()->processed_at);
        $job = Queue::pushed(SendSiteAgentFailureAlertJob::class)->sole();
        $this->assertStringContainsString('להחליף טקסט בדף הבית', $job->bodyText);
        $this->assertStringContainsString(SiteAgentAssistant::NO_VERIFIED_PROPOSAL, $job->bodyText);
        $this->assertStringNotContainsString('diagnostic-query-secret', $job->bodyText);
        Log::shouldHaveReceived('warning')->once()->with('SiteAgent: failure alert history unavailable', [
            'subscriber_id' => $subscriber->id,
            'site_id' => $subscriber->site_id,
            'error_class' => \RuntimeException::class,
        ]);
    }

    public function test_queue_failure_does_not_fail_the_customer_reply_or_log_message_content(): void
    {
        config(['siteagent.alerts.failure_email' => 'admin@example.test']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue secret=password'));
        Log::spy();
        $event = $this->handleMessage($this->subscriber(), 'outgoing-message');
        $this->assertNotNull($event->fresh()->processed_at);
        Log::shouldHaveReceived('warning')->once()->with('SiteAgent: failure alert could not be queued', Mockery::on(fn (array $context): bool => array_keys($context) === ['webhook_event_id', 'subscriber_id', 'error_class']));
    }

    private function handleMessage(SiteAgentSubscriber $subscriber, ?string $delivery, string $reply = SiteAgentAssistant::NO_VERIFIED_PROPOSAL): WebhookEvent
    {
        $event = WebhookEvent::create([
            'source' => WebhookSource::WhatsappCloud, 'event_type' => 'message', 'external_id' => 'incoming-'.$subscriber->id,
            'payload' => ['from' => $subscriber->phone, 'type' => 'text', 'id' => 'incoming', 'text' => ['body' => 'להחליף טקסט בדף הבית']],
        ]);
        $access = Mockery::mock(SiteAgentAccess::class);
        $access->shouldReceive('bindings')->once()->andReturn(collect([$subscriber]));
        $access->shouldReceive('forSubscriber')->once()->andReturn(['status' => SiteAgentAccess::ALLOWED, 'subscriber' => $subscriber]);
        $whatsapp = Mockery::mock(WhatsAppCloudClient::class);
        $whatsapp->shouldReceive('normalize')->once()->andReturn($subscriber->phone);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(function () use ($subscriber, $delivery): ?string {
            Queue::assertNotPushed(SendSiteAgentFailureAlertJob::class);
            $this->message($subscriber, 'concurrent later message');

            return $delivery;
        });
        $conversation = Mockery::mock(SiteAgentConversation::class);
        $conversation->shouldReceive('handle')->once()->andReturnUsing(function ($subscriber, $text, $id, $media, $beforeTurn) use ($reply): string {
            $beforeTurn();

            return $reply;
        });
        $meter = Mockery::mock(SiteAgentUsageMeter::class);
        $meter->shouldReceive('capReached')->once()->andReturnFalse();
        if ($delivery !== null) {
            $meter->shouldReceive('record')->once();
            $this->mock(SiteAgentMessageCap::class)->shouldReceive('warnIfDue')->once();
            $this->mock(SiteAgentWelcome::class)->shouldReceive('sendTipIfDue')->once();
        }
        $this->mock(SiteAgentBilling::class)->shouldReceive('subscriptionForSite')->once()->andReturnNull();
        (new HandleSiteAgentMessageJob($event->id))->handle($access, $whatsapp, $conversation, app(SiteChoice::class), $meter);

        return $event;
    }
}
