<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemUpdates;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SystemLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_is_resilient_and_prune_removes_old_rows(): void
    {
        SystemLog::record('error', 'ai', 'boom', ['x' => 1]);
        $this->assertDatabaseHas('system_logs', ['source' => 'ai', 'message' => 'boom']);

        $old = SystemLog::create(['level' => 'info', 'source' => 'system', 'message' => 'old', 'created_at' => now()->subDays(40)]);
        $this->assertSame(1, SystemLog::prune(30));
        $this->assertDatabaseMissing('system_logs', ['id' => $old->id]);
        // The recent row survives.
        $this->assertDatabaseHas('system_logs', ['message' => 'boom']);
    }

    public function test_a_failed_ai_connection_test_shows_the_real_error_and_logs_it(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'bad-key',
            'billing.ai.provider' => 'anthropic',
            'billing.ai.base_url' => 'https://api.anthropic.test',
            'billing.ai.model' => 'claude-x',
        ]);

        Http::fake([
            'https://api.anthropic.test/*' => Http::response([
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ], 401),
        ]);

        $result = app(ClaudeClient::class)->testConnection();

        $this->assertFalse($result->ok);
        // The provider's real message reaches the screen, not a generic hint.
        $this->assertStringContainsString('invalid x-api-key', $result->message);
        $this->assertStringContainsString('401', $result->message);

        // And it is recorded in the in-panel system log.
        $this->assertDatabaseHas('system_logs', ['level' => 'error', 'source' => 'ai']);
    }

    public function test_the_system_logs_page_lists_entries_and_badges_errors(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertNull(SystemUpdates::getNavigationBadge());

        SystemLog::record('error', 'ai', 'שגיאת ספק', ['status' => 500]);

        $this->assertSame('1', SystemUpdates::getNavigationBadge());

        Livewire::test(SystemUpdates::class)->assertCanSeeTableRecords(SystemLog::all());
    }

    public function test_the_updates_page_shows_the_whats_new_highlights(): void
    {
        config(['changelog.releases' => [
            ['version' => '9.9.9', 'date' => '2026-07-18', 'title' => 'שדרוג בדיקה', 'highlights' => ['יתרון ראשון לבדיקה', 'יתרון שני']],
        ]]);
        $this->actingAs(User::factory()->create());

        Livewire::test(SystemUpdates::class)
            ->assertSeeText('מה חדש')
            ->assertSeeText('שדרוג בדיקה')
            ->assertSeeText('יתרון ראשון לבדיקה');
    }
}
