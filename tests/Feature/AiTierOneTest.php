<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Jobs\ClassifyTicketJob;
use App\Jobs\DraftReplyJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\SupportToolkit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
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

    public function test_it_uses_the_google_gemini_provider_when_selected(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'g-key',
            'billing.ai.provider' => 'google',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'billing.ai.model' => 'gemini-2.5-flash',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'שלום מ-Gemini', 'confidence' => 'high'])]]]]],
            ]),
        ]);

        $ticket = $this->ticketWithInbound();
        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        $draft = $ticket->messages()->where('author', MessageAuthor::Ai)->sole();
        $this->assertStringContainsString('שלום מ-Gemini', $draft->body);

        Http::assertSent(function ($request): bool {
            $schema = $request->data()['generationConfig']['responseSchema'] ?? [];

            return str_contains($request->url(), 'gemini-2.5-flash:generateContent')
                && ($request->header('x-goog-api-key')[0] ?? '') === 'g-key'
                // Schema translated to Gemini's dialect: types upper-cased and
                // additionalProperties dropped (Gemini rejects it).
                && ($schema['type'] ?? '') === 'OBJECT'
                && ! array_key_exists('additionalProperties', $schema)
                && ($schema['properties']['confidence']['type'] ?? '') === 'STRING';
        });
    }

    public function test_google_base_url_is_forgiving_of_a_pasted_openai_compat_path(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'g-key',
            'billing.ai.provider' => 'google',
            // The operator pasted the OpenAI-compatible path by mistake.
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'billing.ai.model' => 'gemini-2.5-flash',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'ok', 'confidence' => 'low'])]]]]],
            ]),
        ]);

        $ticket = $this->ticketWithInbound();
        (new DraftReplyJob($ticket->id))->handle(app(ClaudeClient::class), app(SupportToolkit::class));

        // Reached the native endpoint, not .../v1beta/openai/v1beta/models/...
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1beta/models/gemini-2.5-flash:generateContent')
            && ! str_contains($request->url(), '/openai/'));
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

    public function test_connection_test_reports_unconfigured_when_disabled(): void
    {
        config(['billing.ai.enabled' => false]);

        $this->assertFalse(app(ClaudeClient::class)->testConnection()->configured);
    }

    public function test_connection_test_reports_ok_when_the_provider_responds(): void
    {
        $this->enableAi();
        $this->fakeClaude(['ok' => true]);

        $this->assertTrue(app(ClaudeClient::class)->testConnection()->ok);
    }

    public function test_connection_test_reports_a_failure_on_a_provider_error(): void
    {
        $this->enableAi();
        Http::fake(['https://api.anthropic.test/*' => Http::response('unauthorized', 401)]);

        $result = app(ClaudeClient::class)->testConnection();
        $this->assertFalse($result->ok);
        $this->assertTrue($result->configured); // a real error, not "unconfigured"
    }

    public function test_manual_draft_action_creates_a_draft_on_demand(): void
    {
        $this->actingAs(User::factory()->create());
        $this->enableAi();
        $this->fakeClaude(['reply' => 'טיוטה שהוכנה ידנית', 'confidence' => 'medium']);

        $ticket = $this->ticketWithInbound();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('draftReply');

        $this->assertGreaterThan(0, $ticket->messages()->where('author', MessageAuthor::Ai)->count());
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
