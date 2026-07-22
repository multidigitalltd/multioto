<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_external_id_from_different_sources_are_distinct_events(): void
    {
        [$a, $freshA] = WebhookEvent::record(WebhookSource::Cardcom, 'charge', 'shared-id', ['x' => 1]);
        [$b, $freshB] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'shared-id', ['y' => 2]);

        $this->assertTrue($freshA);
        $this->assertTrue($freshB); // NOT swallowed as a duplicate of the Cardcom one
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_the_same_external_id_from_the_same_source_is_deduped(): void
    {
        [, $first] = WebhookEvent::record(WebhookSource::Cardcom, 'charge', 'dup-id', ['x' => 1]);
        [, $second] = WebhookEvent::record(WebhookSource::Cardcom, 'charge', 'dup-id', ['x' => 1]);

        $this->assertTrue($first);
        $this->assertFalse($second); // duplicate delivery, dropped
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_a_replay_without_an_external_id_is_deduped_by_payload_hash(): void
    {
        // An authenticated redelivery of the exact same id-less payload must not
        // be processed twice — the dedup key falls back to a payload hash.
        [, $first] = WebhookEvent::record(WebhookSource::Waha, 'session.status', null, ['status' => 'WORKING']);
        [, $replay] = WebhookEvent::record(WebhookSource::Waha, 'session.status', null, ['status' => 'WORKING']);

        $this->assertTrue($first);
        $this->assertFalse($replay);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_different_id_less_payloads_are_distinct_events(): void
    {
        [, $a] = WebhookEvent::record(WebhookSource::Waha, 'session.status', null, ['status' => 'WORKING']);
        [, $b] = WebhookEvent::record(WebhookSource::Waha, 'session.status', null, ['status' => 'FAILED']);

        $this->assertTrue($a);
        $this->assertTrue($b); // different content — a real new event
        $this->assertSame(2, WebhookEvent::count());
    }
}
