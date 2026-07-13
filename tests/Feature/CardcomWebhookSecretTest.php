<?php

namespace Tests\Feature;

use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\Setting;
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
}
