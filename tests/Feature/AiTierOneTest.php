<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\ClassifyTicketJob;
use App\Jobs\DraftReplyJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\SupportToolkit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiTierOneTest extends TestCase
{
    use RefreshDatabase;

    protected function enableAi(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-key',
            'billing.ai.base_url' => 'https://api.anthropic.test',
        ]);
    }

    protected function fakeClaude(array $json): void
    {
        Http::fake([
            'https://api.anthropic.test/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode($json)]],
            ]),
        ]);
    }

    protected function ticketWithInbound(): Ticket
    {
        $ticket = Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Email,
            'subject' => 'האתר איטי',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
        ]);

        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'הדפים נטענים לאט מאוד',
            'author' => MessageAuthor::Customer,
        ]);

        return $ticket;
    }

    public function test_ai_layer_is_a_noop_when_disabled(): void
    {
        Http::preventStrayRequests();
        config(['billing.ai.enabled' => false]);

        $ticket = $this->ticketWithInbound();
        (new ClassifyTicketJob($ticket->id))->handle(app(ClaudeClient::class));

        // No AI note added, no HTTP call made.
        $this->assertSame(0, $ticket->messages()->where('author', MessageAuthor::Ai)->count());
    }

    public function test_classification_sets_priority_and_records_an_internal_note(): void
    {
        $this->enableAi();
        Queue::fake([DraftReplyJob::class]);
        $this->fakeClaude(['priority' => 'high', 'category' => 'ביצועים', 'summary' => 'אתר איטי']);

        $ticket = $this->ticketWithInbound();
        (new ClassifyTicketJob($ticket->id))->handle(app(ClaudeClient::class));

        $this->assertSame(TicketPriority::High, $ticket->fresh()->priority);

        $note = $ticket->messages()->where('author', MessageAuthor::Ai)->sole();
        $this->assertSame(MessageChannel::InternalNote, $note->channel);

        Queue::assertPushed(DraftReplyJob::class);
    }

    public function test_draft_reply_is_stored_as_an_internal_note_never_sent(): void
    {
        $this->enableAi();
        $this->fakeClaude(['reply' => 'שלום, בדקנו ונטפל בכך היום.', 'confidence' => 'medium']);

        $ticket = $this->ticketWithInbound();
        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        $draft = $ticket->messages()->where('author', MessageAuthor::Ai)->sole();

        // The draft is an internal note — SendTicketReplyJob skips internal
        // notes, so nothing is ever delivered to the customer without a human.
        $this->assertSame(MessageChannel::InternalNote, $draft->channel);
        $this->assertStringContainsString('טיוטת תשובה', $draft->body);
        $this->assertNull($draft->external_message_id);
    }

    public function test_draft_prompt_includes_real_customer_facts(): void
    {
        $this->enableAi();
        $this->fakeClaude(['reply' => 'טופל', 'confidence' => 'high']);

        $ticket = $this->ticketWithInbound();
        Site::factory()->create([
            'customer_id' => $ticket->customer_id,
            'domain' => 'client-site.co.il',
        ]);

        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        // The outgoing prompt carried the customer's real site domain and a
        // card-update link, so the AI can answer concretely.
        Http::assertSent(function ($request) {
            $prompt = $request['messages'][0]['content'] ?? '';

            return str_contains($prompt, 'client-site.co.il')
                && str_contains($prompt, 'נתוני הלקוח')
                && str_contains($prompt, '/billing/update-card/');
        });
    }

    public function test_editable_persona_flows_into_the_system_prompt(): void
    {
        $this->enableAi();
        config(['billing.ai.persona' => 'PERSONA_MARKER_XYZ']);
        $this->fakeClaude(['reply' => 'ok', 'confidence' => 'low']);

        $ticket = $this->ticketWithInbound();
        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        Http::assertSent(fn ($request) => str_contains($request['system'] ?? '', 'PERSONA_MARKER_XYZ'));
    }

    public function test_it_uses_the_openai_compatible_provider_when_selected(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'openai',
            'billing.ai.base_url' => 'https://api.openai.test/v1',
        ]);
        Http::fake([
            'https://api.openai.test/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['reply' => 'שלום מ-OpenAI', 'confidence' => 'high'])]]],
            ]),
        ]);

        $ticket = $this->ticketWithInbound();
        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        $draft = $ticket->messages()->where('author', MessageAuthor::Ai)->sole();
        $this->assertStringContainsString('שלום מ-OpenAI', $draft->body);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    public function test_draft_is_skipped_when_last_message_is_not_from_customer(): void
    {
        $this->enableAi();
        Http::preventStrayRequests();

        $ticket = $this->ticketWithInbound();
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'כבר עניתי',
            'author' => MessageAuthor::Agent,
        ]);

        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        $this->assertSame(0, $ticket->messages()->where('author', MessageAuthor::Ai)->count());
    }

    public function test_a_safety_refusal_produces_no_draft(): void
    {
        $this->enableAi();
        Http::fake([
            'https://api.anthropic.test/*' => Http::response([
                'stop_reason' => 'refusal',
                'content' => [],
            ]),
        ]);

        $ticket = $this->ticketWithInbound();
        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        $this->assertSame(0, $ticket->messages()->where('author', MessageAuthor::Ai)->count());
    }
}
