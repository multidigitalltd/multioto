<?php

namespace Tests\Feature;

use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\Setting;
use App\Models\WebhookEvent;
use App\Support\CardcomWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CardcomWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_persists_a_secret_when_none_is_configured(): void
    {
        config(['billing.cardcom.webhook_secret' => null]);

        $secret = CardcomWebhook::secret();

        $this->assertNotEmpty($secret);
        // Persisted so it survives, and applied to config for the current request.
        $this->assertSame($secret, Setting::map()['cardcom.webhook_secret'] ?? null);
        $this->assertSame($secret, config('billing.cardcom.webhook_secret'));
    }

    public function test_a_second_generation_reuses_the_stored_secret(): void
    {
        config(['billing.cardcom.webhook_secret' => null]);
        $first = CardcomWebhook::secret();

        // Simulate a fresh request (config back to unset) — must read the stored
        // one, never mint a different secret that would strand an issued URL.
        config(['billing.cardcom.webhook_secret' => null]);
        $second = CardcomWebhook::secret();

        $this->assertSame($first, $second);
        $this->assertSame(1, Setting::query()->where('key', 'cardcom.webhook_secret')->count());
    }

    public function test_it_keeps_a_configured_secret(): void
    {
        config(['billing.cardcom.webhook_secret' => 'preset-secret']);

        $this->assertSame('preset-secret', CardcomWebhook::secret());
    }

    public function test_the_webhook_url_carries_the_secret_and_hits_the_endpoint(): void
    {
        Queue::fake([ProcessCardcomLowProfileJob::class]);
        config(['billing.cardcom.webhook_secret' => 'abc123']);

        $url = CardcomWebhook::url();

        $this->assertStringContainsString('/webhooks/cardcom', $url);
        $this->assertStringContainsString('secret=abc123', $url);

        // The generated secret actually satisfies the fail-closed endpoint.
        $this->post('/webhooks/cardcom?secret=abc123', ['LowProfileId' => 'lp-x'])->assertOk();
        $this->post('/webhooks/cardcom?secret=wrong', ['LowProfileId' => 'lp-x'])->assertForbidden();
    }

    public function test_the_secret_is_never_stored_in_the_recorded_payload(): void
    {
        Queue::fake([ProcessCardcomLowProfileJob::class]);
        config(['billing.cardcom.webhook_secret' => 'abc123']);

        $this->post('/webhooks/cardcom?secret=abc123', ['LowProfileId' => 'lp-secret'])->assertOk();

        // The shared secret must not be persisted to the DB (recoverable from
        // backups/logs would let anyone forge a completion webhook).
        $payload = WebhookEvent::query()->latest('id')->first()->payload;
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertSame('lp-secret', $payload['LowProfileId']);
    }

    public function test_the_secret_is_accepted_via_a_header(): void
    {
        Queue::fake([ProcessCardcomLowProfileJob::class]);
        config(['billing.cardcom.webhook_secret' => 'abc123']);

        // Header route keeps the secret out of URLs/access logs entirely.
        $this->postJson('/webhooks/cardcom', ['LowProfileId' => 'lp-h'], ['X-Webhook-Secret' => 'abc123'])->assertOk();
        $this->postJson('/webhooks/cardcom', ['LowProfileId' => 'lp-h'], ['X-Webhook-Secret' => 'nope'])->assertForbidden();
    }
}
