<?php

namespace Tests\Feature;

use App\Services\Mail\PostmarkClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostmarkClientTest extends TestCase
{
    public function test_it_throws_when_no_account_token_is_configured(): void
    {
        config(['services.postmark.account_token' => null]);

        $this->expectException(\RuntimeException::class);

        app(PostmarkClient::class)->verifiedIdentities();
    }

    public function test_it_parses_verified_senders_and_domains(): void
    {
        config(['services.postmark.account_token' => 'acct-token']);
        Http::fake([
            '*/senders*' => Http::response(['SenderSignatures' => [
                ['EmailAddress' => 'support@multidigital.co.il', 'Name' => 'Multi Digital', 'Confirmed' => true],
                ['EmailAddress' => 'pending@multidigital.co.il', 'Name' => null, 'Confirmed' => false],
            ]]),
            '*/domains*' => Http::response(['Domains' => [
                ['Name' => 'multidigital.co.il'],
            ]]),
        ]);

        $identities = app(PostmarkClient::class)->verifiedIdentities();

        $this->assertCount(2, $identities['senders']);
        $this->assertTrue($identities['senders'][0]['confirmed']);
        $this->assertSame(['multidigital.co.il'], $identities['domains']);

        // The account token must ride in the header, not the body.
        Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Account-Token', 'acct-token'));
    }

    public function test_verified_sender_matches_confirmed_signature_or_verified_domain(): void
    {
        $client = app(PostmarkClient::class);
        $identities = [
            'senders' => [
                ['email' => 'support@multidigital.co.il', 'name' => null, 'confirmed' => true],
                ['email' => 'pending@other.co.il', 'name' => null, 'confirmed' => false],
            ],
            'domains' => ['multi.digital'],
        ];

        // Confirmed signature (case-insensitive).
        $this->assertTrue($client->isVerifiedSender('Support@Multidigital.co.il', $identities));
        // Any address under a verified domain.
        $this->assertTrue($client->isVerifiedSender('billing@multi.digital', $identities));
        // Unconfirmed signature is not accepted.
        $this->assertFalse($client->isVerifiedSender('pending@other.co.il', $identities));
        // Unknown address.
        $this->assertFalse($client->isVerifiedSender('nobody@example.com', $identities));
    }

    public function test_it_reports_a_rejected_account_token(): void
    {
        config(['services.postmark.account_token' => 'bad']);
        Http::fake(['*/senders*' => Http::response(['ErrorCode' => 10], 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Account Token/');

        app(PostmarkClient::class)->verifiedIdentities();
    }
}
