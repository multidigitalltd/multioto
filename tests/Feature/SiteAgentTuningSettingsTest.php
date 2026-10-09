<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSiteAgent;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Services\Ai\GeminiContextCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SiteAgentTuningSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Every form value lands at the config path read by the assistant. */
    public function test_admin_can_save_and_reload_agent_tuning_without_losing_zero_values(): void
    {
        $this->actingAs(User::factory()->create());
        $values = [
            'persona' => '  עוזר לבעלי אתרים  ',
            'style' => "תשובות קצרות.\nפנה בלשון רבים.",
            'work_rules' => 'הצג את הטקסט הקיים ואת השינוי המוצע.',
            'instructions' => 'ההנחיות הקיימות נשארות בתוקף.',
            'history_messages' => 0,
            'history_hours' => 72,
            'history_chars' => 0,
            'max_turns' => 8,
            'budget_seconds' => 120,
            'tool_result_chars' => 9000,
            'transcript_days' => 30,
            'cache_enabled' => false,
            'cache_ttl_minutes' => 120,
        ];

        $page = Livewire::test(ManageSiteAgent::class);
        foreach ($values as $key => $value) {
            $page->set('data.siteagent.'.$key, $value);
        }
        $page->call('save')->assertHasNoFormErrors();

        $stored = Setting::map();
        foreach ($values as $key => $value) {
            $expected = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
            $this->assertSame($expected, $stored['siteagent.'.$key]);
            $path = SettingsServiceProvider::MAP['siteagent.'.$key];
            $this->assertSame(is_bool($value) ? $value : $expected, config($path));
        }

        Livewire::test(ManageSiteAgent::class)
            ->assertSet('data.siteagent.persona', 'עוזר לבעלי אתרים')
            ->assertSet('data.siteagent.history_messages', '0')
            ->assertSet('data.siteagent.history_chars', '0')
            ->assertSet('data.siteagent.cache_enabled', false)
            ->assertSet('data.siteagent.cache_ttl_minutes', '120');
    }

    public function test_clearing_tuning_restores_defaults_in_a_running_worker(): void
    {
        $this->actingAs(User::factory()->create());
        $keys = [
            'persona', 'style', 'work_rules', 'instructions', 'history_messages',
            'history_hours', 'history_chars', 'max_turns', 'budget_seconds',
            'tool_result_chars', 'transcript_days', 'cache_ttl_minutes',
        ];
        $defaults = [];
        foreach ($keys as $key) {
            $path = SettingsServiceProvider::MAP['siteagent.'.$key];
            $defaults[$path] = SettingsServiceProvider::pristine($path);
            Setting::put('siteagent.'.$key, 'previous override');
        }
        SettingsServiceProvider::refreshFromDatabase();
        $this->assertSame('previous override', config('siteagent.assistant.work_rules'));

        $page = Livewire::test(ManageSiteAgent::class);
        foreach ($keys as $key) {
            $page->set('data.siteagent.'.$key, '');
        }
        $page->call('save')->assertHasNoFormErrors();

        foreach ($keys as $key) {
            $this->assertArrayNotHasKey('siteagent.'.$key, Setting::map());
        }
        foreach ($defaults as $path => $value) {
            $this->assertSame($value, config($path));
        }

        // A job refreshes the settings on an already-running Horizon worker.
        Setting::put('siteagent.style', 'סגנון חדש');
        dispatch(fn () => null);
        $this->assertSame('סגנון חדש', config('siteagent.assistant.style'));
        Setting::forget('siteagent.style');
        dispatch(fn () => null);
        $this->assertSame($defaults['siteagent.assistant.style'], config('siteagent.assistant.style'));
    }

    public function test_cache_toggle_is_boolean_and_removing_its_override_resets_a_worker(): void
    {
        $default = SettingsServiceProvider::pristine('siteagent.assistant.cache.enabled');
        foreach ([true, false] as $enabled) {
            Setting::put('siteagent.cache_enabled', $enabled ? '1' : '0');
            SettingsServiceProvider::refreshFromDatabase();
            $this->assertSame($enabled, config('siteagent.assistant.cache.enabled'));
        }

        Setting::forget('siteagent.cache_enabled');
        SettingsServiceProvider::refreshFromDatabase();
        $this->assertSame($default, config('siteagent.assistant.cache.enabled'));
    }

    #[DataProvider('invalidNumericSettings')]
    public function test_invalid_tuning_is_rejected_before_any_setting_is_saved(array $values): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('siteagent.instructions', 'הוראה קיימת');
        $page = Livewire::test(ManageSiteAgent::class)
            ->set('data.siteagent.instructions', 'אסור לשמור אם הטופס שגוי');
        foreach ($values as $key => $value) {
            $page->set('data.siteagent.'.$key, $value);
        }
        $page->call('save')->assertHasFormErrors(array_map(
            fn (string $key): string => 'siteagent.'.$key,
            array_keys($values),
        ));
        $this->assertSame('הוראה קיימת', Setting::map()['siteagent.instructions']);
        $this->assertArrayNotHasKey('siteagent.cache_enabled', Setting::map());
    }

    public static function invalidNumericSettings(): array
    {
        $minima = [
            'history_messages' => 0, 'history_hours' => 1, 'history_chars' => 0,
            'max_turns' => 2, 'budget_seconds' => 30, 'tool_result_chars' => 1000,
            'transcript_days' => 1, 'cache_ttl_minutes' => 15,
        ];
        $maxima = [
            'history_messages' => 80, 'history_hours' => 2160, 'history_chars' => 48000,
            'max_turns' => 10, 'budget_seconds' => 240, 'tool_result_chars' => 12000,
            'transcript_days' => 90, 'cache_ttl_minutes' => 1440,
        ];

        return [
            'below minimum' => [array_map(fn (int $value): int => $value - 1, $minima)],
            'above maximum' => [array_map(fn (int $value): int => $value + 1, $maxima)],
            'fractional values' => [array_map(fn (int $value): float => $value + 0.5, $minima)],
            'not numbers' => [array_fill_keys(array_keys($minima), 'unbounded')],
        ];
    }

    public function test_each_instruction_section_is_bounded_and_requires_text(): void
    {
        $this->actingAs(User::factory()->create());
        $keys = ['persona', 'style', 'work_rules', 'instructions'];
        $page = Livewire::test(ManageSiteAgent::class);
        foreach ($keys as $key) {
            $page->set('data.siteagent.'.$key, str_repeat('א', 4001));
        }
        $page->call('save')->assertHasFormErrors(array_map(
            fn (string $key): string => 'siteagent.'.$key,
            $keys,
        ));
        $this->assertSame([], Setting::map());

        foreach ($keys as $key) {
            $page->set('data.siteagent.'.$key, ['not text']);
        }
        $page->call('save')->assertHasFormErrors(array_map(
            fn (string $key): string => 'siteagent.'.$key,
            $keys,
        ));
        $this->assertSame([], Setting::map());
    }

    public function test_replayed_unknown_fields_cannot_override_permissions_or_other_configuration(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageSiteAgent::class)
            ->set('data.siteagent.approval_required', false)
            ->set('data.siteagent.cache_namespace', 'another-customer')
            ->set('data.ai.api_key', 'replayed-key')
            ->call('save')
            ->assertHasNoFormErrors();

        foreach (['siteagent.approval_required', 'siteagent.cache_namespace', 'ai.api_key'] as $key) {
            $this->assertArrayNotHasKey($key, Setting::map());
        }
    }

    public function test_non_admin_cannot_access_or_directly_save_tuning(): void
    {
        $this->actingAs(User::factory()->agent()->create());
        Livewire::test(ManageSiteAgent::class)->assertForbidden();
        $this->assertSame([], Setting::map());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        app(ManageSiteAgent::class)->save();
    }

    public function test_screen_explains_cache_expiry_and_keeps_approval_safeguards_explicit(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageSiteAgent::class)
            ->assertSeeText('זהות ותפקיד')
            ->assertSeeText('סגנון התשובות')
            ->assertSeeText('כללי עבודה')
            ->assertSeeText('זיכרון השיחה')
            ->assertSeeText('גובה גם על אחסון המטמון')
            ->assertSeeText('בנייה מחדש בשימוש הבא')
            ->assertSeeText('עלות האחסון שלהם נמשכת עד אז')
            ->assertSeeText('אינו רשאי לבצע שינוי לפני אישור');
    }

    public function test_requesting_cache_rebuild_does_not_contact_a_provider_or_save_unsaved_form_values(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->partialMock(GeminiContextCache::class, function ($mock): void {
            $mock->shouldReceive('invalidate')->once();
        });

        Livewire::test(ManageSiteAgent::class)
            ->set('data.siteagent.persona', 'שינוי שלא נשמר')
            ->call('rebuildAssistantCache')
            ->assertNotified('המטמון ייבנה מחדש בשימוש הבא');

        Http::assertNothingSent();
        $this->assertSame([], Setting::map());
    }

    public function test_status_only_renders_safe_metadata_and_handles_unsupported_caching(): void
    {
        $this->actingAs(User::factory()->create());
        config(['billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-3.1-flash-lite']);
        $this->mock(GeminiContextCache::class, function ($mock): void {
            $mock->shouldReceive('status')->andReturn([
                'state' => 'fallback', 'reason' => 'model_unsupported',
                // Even unexpected extra metadata cannot leak through the screen.
                'name' => 'cachedContents/private-resource',
                'error' => '<script>provider-secret</script>',
            ]);
        });

        Livewire::test(ManageSiteAgent::class)
            ->assertSeeText('google · gemini-3.1-flash-lite')
            ->assertSeeText('המודל שנבחר אינו תומך במטמון הזה')
            ->assertDontSeeText('cachedContents/private-resource')
            ->assertDontSeeText('provider-secret');
    }

    public function test_cache_rebuild_failure_is_safe_and_does_not_claim_success(): void
    {
        $this->actingAs(User::factory()->create());
        $this->partialMock(GeminiContextCache::class, function ($mock): void {
            $mock->shouldReceive('invalidate')->once()->andThrow(new RuntimeException('internal-cache-secret'));
        });

        Livewire::test(ManageSiteAgent::class)
            ->call('rebuildAssistantCache')
            ->assertNotified('לא ניתן לבקש בנייה מחדש כרגע')
            ->assertDontSeeText('internal-cache-secret');
    }

    public function test_non_admin_cannot_request_a_cache_rebuild(): void
    {
        $this->actingAs(User::factory()->agent()->create());
        $this->mock(GeminiContextCache::class, function ($mock): void {
            $mock->shouldNotReceive('invalidate');
        });

        try {
            app(ManageSiteAgent::class)->rebuildAssistantCache();
            $this->fail('A non-admin must not invalidate the cache.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
