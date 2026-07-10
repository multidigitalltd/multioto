<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsSetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_an_allow_listed_key_encrypted_and_trimmed(): void
    {
        $this->artisan('settings:set', ['key' => 'linet.doctype', 'value' => ' 9 '])
            ->assertExitCode(0);

        $this->assertSame('9', Setting::map()['linet.doctype'] ?? null);
    }

    public function test_rejects_keys_outside_the_allow_list(): void
    {
        $this->artisan('settings:set', ['key' => 'app.debug', 'value' => '1'])
            ->assertExitCode(2);

        $this->assertArrayNotHasKey('app.debug', Setting::map());
    }

    public function test_show_lists_keys_without_values(): void
    {
        Setting::put('linet.key', 'secret-value');

        $this->artisan('settings:set', ['--show' => true])
            ->expectsOutputToContain('linet.key')
            ->doesntExpectOutputToContain('secret-value')
            ->assertExitCode(0);
    }
}
