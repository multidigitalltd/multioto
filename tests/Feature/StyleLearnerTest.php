<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\DraftReplyJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\StyleLearner;
use App\Services\Ai\SupportToolkit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StyleLearnerTest extends TestCase
{
    use RefreshDatabase;

    private function enableAi(): void
    {
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'k',
            'billing.ai.base_url' => 'https://api.anthropic.test',
            'billing.ai.provider' => 'anthropic', 'billing.ai.model' => 'claude-x',
        ]);
    }

    private function agentReplies(int $n): void
    {
        $ticket = Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Email, 'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        for ($i = 0; $i < $n; $i++) {
            $ticket->messages()->create([
                'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
                'body' => "תשובה מספר {$i} — בברכה, הצוות", 'author' => MessageAuthor::Agent,
            ]);
        }
    }

    public function test_it_summarises_recent_agent_replies_and_persists(): void
    {
        $this->enableAi();
        Http::fake(['https://api.anthropic.test/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['summary' => 'טון חם וקצר, נחתם ב"בברכה, הצוות".'])]],
        ])]);
        $this->agentReplies(6);

        $summary = app(StyleLearner::class)->refresh();

        $this->assertNotNull($summary);
        $this->assertStringContainsString('בברכה', $summary);
        // Live in config and persisted as a setting.
        $this->assertSame($summary, config('billing.ai.style_summary'));
        $this->assertArrayHasKey('ai.style_summary', Setting::map());
    }

    public function test_it_needs_a_minimum_number_of_replies(): void
    {
        $this->enableAi();
        Http::preventStrayRequests();
        $this->agentReplies(2); // below StyleLearner::MIN_REPLIES

        $this->assertNull(app(StyleLearner::class)->refresh());
    }

    public function test_the_learned_style_flows_into_the_draft_prompt(): void
    {
        $this->enableAi();
        config(['billing.ai.style_summary' => 'STYLE_MARKER_123']);
        Http::fake(['https://api.anthropic.test/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['reply' => 'ok', 'confidence' => 'low'])]],
        ])]);

        $ticket = Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Email, 'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'body' => 'שאלה', 'author' => MessageAuthor::Customer,
        ]);

        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        Http::assertSent(fn ($request) => str_contains($request['system'] ?? '', 'STYLE_MARKER_123'));
    }
}
