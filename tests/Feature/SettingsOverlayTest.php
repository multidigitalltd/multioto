<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SettingsOverlayTest extends TestCase
{
    use RefreshDatabase;

    private function applyOverlay(): void
    {
        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyOverlay');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    public function test_clearing_an_ai_override_reverts_to_the_config_default(): void
    {
        $default = config('billing.ai.site_rules');
        $this->assertNotSame('', (string) $default);

        // Admin sets a custom value — it overlays onto config.
        Setting::put('ai.site_rules', 'CUSTOM SITE RULES');
        $this->applyOverlay();
        $this->assertSame('CUSTOM SITE RULES', config('billing.ai.site_rules'));

        // Admin clears it — a long-running worker must revert to the config-file
        // default, not keep the removed instructions in force.
        Setting::forget('ai.site_rules');
        $this->applyOverlay();
        $this->assertSame($default, config('billing.ai.site_rules'));
    }
}
