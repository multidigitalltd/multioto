<?php

namespace Tests\Feature;

use App\Enums\ServiceMode;
use App\Enums\TaskStatus;
use App\Models\ServiceException;
use App\Models\Task;
use App\Services\Agent\CommandInterpreter;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AgentCalendarAwarenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.ai.enabled' => true]);
        // A Thursday in Tammuz 5786 — a plain working day.
        Carbon::setTestNow(Carbon::parse('2026-07-16 09:00', 'Asia/Jerusalem'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_read_calendar_reports_tasks_service_days_and_shabbat(): void
    {
        Task::create(['title' => 'לחדש דומיין ללקוח', 'status' => TaskStatus::Open, 'due_at' => '2026-07-20 10:00']);
        Task::create(['title' => 'משימה שהושלמה', 'status' => TaskStatus::Done, 'due_at' => '2026-07-20 11:00']);
        ServiceException::create(['starts_on' => '2026-07-22', 'ends_on' => '2026-07-22', 'mode' => ServiceMode::Reduced]);

        // The model calls read_calendar; feed its output back as the answer.
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(
            fn (string $system, string $prompt, array $tools, callable $handler): string => $handler('read_calendar', ['date' => '2026-07-16', 'days' => 10])['content']
        );
        $this->app->instance(ClaudeClient::class, $claude);

        $command = app(CommandInterpreter::class)->run('מה יש בלוח בשבוע הקרוב?');

        $this->assertStringContainsString('לחדש דומיין ללקוח', $command->result); // open task in range
        $this->assertStringContainsString('שבת', $command->result);              // Saturday 2026-07-18 with times
        $this->assertStringContainsString('כניסה', $command->result);            // candle-lighting time shown
        $this->assertStringContainsString('מתכונת מצומצמת', $command->result);   // the marked service day
        $this->assertStringNotContainsString('משימה שהושלמה', $command->result); // done tasks excluded
    }

    public function test_the_system_prompt_carries_todays_operating_context(): void
    {
        ServiceException::create([
            'starts_on' => '2026-07-16', 'ends_on' => '2026-07-16',
            'mode' => ServiceMode::UrgentOnly, 'note' => 'הערה פנימית סודית',
        ]);

        $captured = null;
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(function (string $system) use (&$captured): string {
            $captured = $system;

            return 'בסדר.';
        });
        $this->app->instance(ClaudeClient::class, $claude);

        app(CommandInterpreter::class)->run('שלום');

        $this->assertStringContainsString('הקשר תפעולי', (string) $captured);
        $this->assertStringContainsString('16/07/2026', (string) $captured);   // today's date
        $this->assertStringContainsString('דחוף בלבד', (string) $captured);    // today's service mode
    }

    public function test_the_system_prompt_notes_the_shabbat_pause_when_active(): void
    {
        // On Shabbat with blocking on, the agent is told automations are paused.
        config(['billing.shabbat.block_automations' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00', 'Asia/Jerusalem')); // Saturday

        $captured = null;
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(function (string $system) use (&$captured): string {
            $captured = $system;

            return 'בסדר.';
        });
        $this->app->instance(ClaudeClient::class, $claude);

        app(CommandInterpreter::class)->run('אפשר לשלוח דיוור עכשיו?');

        $this->assertStringContainsString('מושהות עד', (string) $captured);
    }
}
