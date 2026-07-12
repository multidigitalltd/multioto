<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageAiAgent;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageAiAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_settings_persist_and_reappear_after_save(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageAiAgent::class)
            ->set('data.ai.enabled', true)
            ->set('data.ai.provider', 'google')
            ->set('data.ai.model', 'gemini-2.5-flash')
            ->set('data.ai.persona', 'אתה מומחה WordPress של Multi Digital')
            ->set('data.ai.rules', 'כלל ראשון')
            ->call('save');

        $this->assertSame('אתה מומחה WordPress של Multi Digital', Setting::map()['ai.persona'] ?? null);
        $this->assertSame('gemini-2.5-flash', Setting::map()['ai.model'] ?? null);

        // Fresh page load: the overlay applies stored settings, mount refills.
        (new SettingsServiceProvider(app()))->boot();

        Livewire::test(ManageAiAgent::class)
            ->assertSet('data.ai.persona', 'אתה מומחה WordPress של Multi Digital')
            ->assertSet('data.ai.rules', 'כלל ראשון')
            ->assertSet('data.ai.model', 'gemini-2.5-flash')
            ->assertSet('data.ai.provider', 'google');
    }
}
