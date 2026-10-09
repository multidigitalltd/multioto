<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSiteAgent;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SiteAgentFailureAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SETTING = 'siteagent.failure_alert_email';

    private const CONFIG = 'siteagent.alerts.failure_email';

    public function test_admin_can_mount_save_and_reload_one_alert_email(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        config([self::CONFIG => 'initial@example.test']);

        Livewire::test(ManageSiteAgent::class)
            ->assertSet('data.siteagent.failure_alert_email', 'initial@example.test')
            ->set('data.siteagent.failure_alert_email', '  bot-alerts@example.test  ')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.siteagent.failure_alert_email', 'bot-alerts@example.test');

        $this->assertSame('bot-alerts@example.test', Setting::map()[self::SETTING]);
        $this->assertSame('bot-alerts@example.test', config(self::CONFIG));
        Livewire::test(ManageSiteAgent::class)
            ->assertSet('data.siteagent.failure_alert_email', 'bot-alerts@example.test');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_blank_removes_the_override_and_restores_the_default_in_a_worker(): void
    {
        $this->actingAs(User::factory()->create());
        $default = SettingsServiceProvider::pristine(self::CONFIG);
        Setting::put(self::SETTING, 'previous@example.test');
        SettingsServiceProvider::refreshFromDatabase();

        Livewire::test(ManageSiteAgent::class)
            ->assertSet('data.siteagent.failure_alert_email', 'previous@example.test')
            ->set('data.siteagent.failure_alert_email', '')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.siteagent.failure_alert_email', $default);

        $this->assertArrayNotHasKey(self::SETTING, Setting::map());
        $this->assertSame($default, config(self::CONFIG));

        Setting::put(self::SETTING, 'new@example.test');
        dispatch(fn () => null);
        $this->assertSame('new@example.test', config(self::CONFIG));

        Setting::forget(self::SETTING);
        dispatch(fn () => null);
        $this->assertSame($default, config(self::CONFIG));
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_addresses_are_rejected_before_any_setting_is_saved(mixed $email): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put(self::SETTING, 'kept@example.test');
        Setting::put('siteagent.instructions', 'הנחיה קיימת');
        SettingsServiceProvider::refreshFromDatabase();

        Livewire::test(ManageSiteAgent::class)
            ->set('data.siteagent.failure_alert_email', $email)
            ->set('data.siteagent.instructions', 'אין לשמור כאשר הכתובת שגויה')
            ->call('save')
            ->assertHasFormErrors(['siteagent.failure_alert_email']);

        $this->assertSame('kept@example.test', Setting::map()[self::SETTING]);
        $this->assertSame('הנחיה קיימת', Setting::map()['siteagent.instructions']);
        $this->assertSame('kept@example.test', config(self::CONFIG));
    }

    public static function invalidEmails(): array
    {
        return [
            'missing domain' => ['invalid-address'],
            'multiple addresses' => ['one@example.test,two@example.test'],
            'header injection' => ["one@example.test\r\nBcc: two@example.test"],
            'too long' => [str_repeat('a', 245).'@example.test'],
            'array' => [['one@example.test']],
        ];
    }

    public function test_non_admin_cannot_view_or_directly_save_the_destination(): void
    {
        $this->actingAs(User::factory()->agent()->create());
        Livewire::test(ManageSiteAgent::class)->assertForbidden();
        $this->assertSame([], Setting::map());

        try {
            app(ManageSiteAgent::class)->save();
            $this->fail('Only an admin may change the conversation alert recipient.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame([], Setting::map());
    }

    public function test_screen_describes_the_report_scope_and_admin_only_default_recipient(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageSiteAgent::class)
            ->assertSeeText('התראות על בקשות שלא הובנו')
            ->assertSeeText('כתובת אימייל לקבלת ההתראות')
            ->assertSeeText('עד 40 הודעות אחרונות שנשמרו מאותה שיחה')
            ->assertSeeText('משתמשים עם תפקיד מנהל במערכת')
            ->assertSeeText('מועד האירוע')
            ->assertSeeText('קישור לשיחה במערכת');
    }
}
