<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\WebhookSource;
use App\Jobs\CheckSiteAgentChannelJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Providers\SettingsServiceProvider;
use App\Services\Notifications\TeamNotifier;
use App\Support\WebhookRejections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * ניטור הערוץ הנכנס של בוט ניהול האתר.
 *
 * זה הכישלון היחיד במוצר שאינו מייצר שגיאה בשום מקום: הלקוח כותב, לא קורה כלום,
 * ואין שורה באף יומן. מבחינת המערכת פשוט לא נשלחה הודעה — ולכן אף אחד לא יודע
 * שהמוצר שהלקוח משלם עליו שותק.
 *
 * הבדיקות כאן הן על ההבחנה שעושה את ההבדל: "מטא לא שלחה" ו"קיבלנו ודחינו" הם
 * אותה שתיקה בדיוק מבחוץ, ושני תיקונים שונים לגמרי.
 */
class SiteAgentChannelWatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stored, not config()'d: the job runs on the queue and the settings
        // overlay reverts a runtime value that no stored row backs.
        foreach ([
            'siteagent.enabled' => '1',
            'siteagent.phone_number_id' => '123456',
            'siteagent.token' => 'token',
            'siteagent.app_secret' => 'secret',
            'siteagent.verify_token' => 'verify',
            'siteagent.template_verification' => 'code_template',
            'siteagent.template_paused' => 'paused_template',
            'siteagent.template_resumed' => 'resumed_template',
        ] as $key => $value) {
            Setting::put($key, $value);
        }

        SettingsServiceProvider::refreshFromDatabase();
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);

        return SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $customer->id,
            'site_id' => $site->id,
        ]);
    }

    private function accepted(): void
    {
        WebhookEvent::create([
            'source' => WebhookSource::WhatsappCloud,
            'event_type' => 'message',
            'external_id' => 'wamid.'.bin2hex(random_bytes(4)),
            'payload' => [],
        ]);
    }

    private function expectAlert(string $contains): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title): bool => str_contains($title, $contains));
        $this->app->instance(TeamNotifier::class, $team);
    }

    private function expectSilence(): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->never();
        $this->app->instance(TeamNotifier::class, $team);
    }

    /**
     * הדחייה — התקלה הדחופה, ויש לה סיבה אחת בלבד.
     *
     * מטא מנסה ואנחנו סוגרים את הדלת. זה תמיד סוד אפליקציה שאינו תואם, וזה תמיד
     * תיקון של שדה אחד — ולכן ההתראה אומרת בדיוק את זה ולא "בדקו את ההגדרות".
     */
    public function test_rejected_deliveries_raise_the_alarm(): void
    {
        $this->subscriber();
        WebhookRejections::record(CheckSiteAgentChannelJob::CHANNEL);
        $this->expectAlert('דוחים אותן');

        CheckSiteAgentChannelJob::dispatchSync();

        $this->assertTrue(SystemLog::where('source', 'site-agent')
            ->where('message', 'like', '%דוחים אותן%')->exists());
    }

    /**
     * דחייה שקדמה למסירה שהתקבלה אינה תקלה פתוחה.
     *
     * אחרת כל סוד שהוחלף אי פעם היה ממשיך להתריע לנצח, גם אחרי שתוקן — וזו
     * הדרך הקצרה ביותר ללמד צוות לדלג על ההתראה הזאת.
     */
    public function test_an_old_rejection_before_a_good_delivery_is_not_reported(): void
    {
        $this->subscriber();
        WebhookRejections::record(CheckSiteAgentChannelJob::CHANNEL);
        $this->travel(2)->hours();
        $this->accepted();
        $this->expectSilence();

        CheckSiteAgentChannelJob::dispatchSync();
    }

    /** ערוץ שמעולם לא העביר דבר, בזמן שיש מי שכותב אליו. */
    public function test_a_channel_that_never_carried_anything_is_reported(): void
    {
        $this->subscriber();
        $this->expectAlert('מעולם לא התקבלה הודעה');

        CheckSiteAgentChannelJob::dispatchSync();
    }

    /** אבל לא כשאין עדיין אף מנוי — אין ממי לצפות להודעה. */
    public function test_silence_without_subscribers_is_not_a_fault(): void
    {
        $this->expectSilence();

        CheckSiteAgentChannelJob::dispatchSync();
    }

    /**
     * ושקט אחרי שכן עברו הודעות אינו מדווח כלל.
     *
     * לקוחות אינם כותבים כל שעה. התראה על יום שקט היא התראה שלומדים להתעלם
     * ממנה, ואז גם האמיתית נבלעת איתה.
     */
    public function test_a_quiet_day_on_a_working_channel_is_not_a_fault(): void
    {
        $this->subscriber();
        $this->accepted();
        $this->travel(9)->days();
        $this->expectSilence();

        CheckSiteAgentChannelJob::dispatchSync();
    }

    /** מוצר שאינו מוכן אינו מדווח — מסך המוכנות כבר אומר מה חסר. */
    public function test_an_unready_product_is_left_to_its_readiness_screen(): void
    {
        Setting::put('siteagent.template_verification', '');
        SettingsServiceProvider::refreshFromDatabase();
        $this->subscriber();
        $this->expectSilence();

        CheckSiteAgentChannelJob::dispatchSync();
    }

    /** וכיבוי מפורש משתיק את הניטור. */
    public function test_the_watch_can_be_switched_off(): void
    {
        config(['siteagent.channel_watch.enabled' => false]);
        $this->subscriber();
        WebhookRejections::record(CheckSiteAgentChannelJob::CHANNEL);
        $this->expectSilence();

        CheckSiteAgentChannelJob::dispatchSync();
    }

    /**
     * אותה תקלה מדווחת פעם ביום, לא בכל ריצה.
     *
     * הבדיקה רצה כל שעה ושני המצבים נמשכים עד שמישהו מתקן, כך שבלי הזה אותו
     * משפט היה חוזר עשרים פעם לפני שמישהו קרא את הראשון.
     */
    public function test_the_same_fault_is_announced_once_a_day(): void
    {
        $this->subscriber();
        WebhookRejections::record(CheckSiteAgentChannelJob::CHANNEL);
        $this->expectAlert('דוחים אותן');

        CheckSiteAgentChannelJob::dispatchSync();
        CheckSiteAgentChannelJob::dispatchSync();
        CheckSiteAgentChannelJob::dispatchSync();

        // The log, unlike the alert, is written every run — the alert is for
        // attention, the log answers "since when".
        $this->assertSame(3, SystemLog::where('source', 'site-agent')
            ->where('message', 'like', '%דוחים אותן%')->count());
    }

    /** ההתראה מגיעה גם לפעמון בפאנל, לא רק לוואטסאפ ולמייל. */
    public function test_the_managers_get_it_in_the_panel_too(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->subscriber();
        WebhookRejections::record(CheckSiteAgentChannelJob::CHANNEL);
        $this->expectAlert('דוחים אותן');

        CheckSiteAgentChannelJob::dispatchSync();

        $this->assertSame(1, $admin->notifications()->count());
    }
}
