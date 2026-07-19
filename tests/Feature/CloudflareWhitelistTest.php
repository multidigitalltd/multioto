<?php

namespace Tests\Feature;

use App\Services\Cloudflare\CloudflareClient;
use App\Services\System\OutboundIp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareWhitelistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Fake the Cloudflare API: only $zoneName resolves to a zone; access-rules
     * GET returns $existing, POST always succeeds.
     */
    private function fakeCloudflare(string $zoneName, string $zoneId, array $existing = []): void
    {
        Http::fake([
            '*/access_rules/rules*' => fn ($request) => $request->method() === 'GET'
                ? Http::response(['success' => true, 'result' => $existing])
                : Http::response(['success' => true, 'result' => ['id' => 'rule_new']]),
            '*/zones*' => function ($request) use ($zoneName, $zoneId) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

                return Http::response(['success' => true, 'result' => ($q['name'] ?? '') === $zoneName
                    ? [['id' => $zoneId]]
                    : []]);
            },
        ]);
    }

    public function test_it_whitelists_the_ip_resolving_a_subdomain_to_its_zone(): void
    {
        $this->fakeCloudflare('example.co.il', 'zone_1');

        $result = app(CloudflareClient::class)->whitelistIp('cf-token', 'shop.example.co.il', '203.0.113.7', 'note');

        $this->assertTrue($result['ok']);

        // A whitelist rule was created for our IP.
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/zones/zone_1/firewall/access_rules/rules')
            && data_get($request->data(), 'mode') === 'whitelist'
            && data_get($request->data(), 'configuration.value') === '203.0.113.7');
    }

    public function test_an_existing_rule_is_idempotent_and_creates_nothing(): void
    {
        $this->fakeCloudflare('example.co.il', 'zone_1', existing: [['id' => 'rule_existing']]);

        $result = app(CloudflareClient::class)->whitelistIp('cf-token', 'example.co.il', '203.0.113.7', 'note');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('כבר מוחרגת', $result['message']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_it_fails_clearly_when_no_zone_matches_the_domain(): void
    {
        $this->fakeCloudflare('other.com', 'zone_9');

        $result = app(CloudflareClient::class)->whitelistIp('cf-token', 'example.co.il', '203.0.113.7', 'note');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('לא נמצא Zone', $result['message']);
    }

    public function test_it_rejects_a_missing_token_or_bad_ip_without_calling_cloudflare(): void
    {
        Http::fake();

        $this->assertFalse(app(CloudflareClient::class)->whitelistIp('', 'example.co.il', '203.0.113.7', 'n')['ok']);
        $this->assertFalse(app(CloudflareClient::class)->whitelistIp('t', 'example.co.il', 'not-an-ip', 'n')['ok']);

        Http::assertNothingSent();
    }

    public function test_a_connection_failure_yields_a_notice_not_an_exception(): void
    {
        Http::fake([
            '*/access_rules/rules*' => fn () => throw new ConnectionException('down'),
            '*/zones*' => Http::response(['success' => true, 'result' => [['id' => 'zone_1']]]),
        ]);

        $result = app(CloudflareClient::class)->whitelistIp('cf-token', 'example.co.il', '203.0.113.7', 'note');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('נכשלה', $result['message']);
    }

    public function test_outbound_ip_is_detected_and_cached(): void
    {
        Http::fake(['*' => Http::response("198.51.100.42\n")]);

        $this->assertSame('198.51.100.42', app(OutboundIp::class)->current());

        // Cached — a second call makes no further request.
        Http::fake(['*' => Http::response('10.0.0.1')]);
        $this->assertSame('198.51.100.42', app(OutboundIp::class)->current());
    }

    public function test_a_failed_ip_probe_is_cached_so_it_is_not_re_probed(): void
    {
        Http::fake(['*' => Http::response('not-an-ip')]);

        $this->assertNull(app(OutboundIp::class)->current());
        $this->assertNull(app(OutboundIp::class)->current());

        // Only the first call probed the echo services (3 URLs); the second used
        // the cached failure sentinel instead of re-probing.
        Http::assertSentCount(3);
    }
}
