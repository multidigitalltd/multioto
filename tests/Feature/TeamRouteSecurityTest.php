<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\TranscriptionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The panel's authentication and module grants also govern direct team URLs. */
class TeamRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('teamRoutes')]
    public function test_guests_cannot_access_team_routes(string $method, string $name): void
    {
        $this->call($method, $this->teamUrl($name))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    #[DataProvider('teamRoutes')]
    public function test_pending_two_factor_cannot_access_team_routes(string $method, string $name): void
    {
        // In particular, transcription must never reach its external service.
        $this->mock(TranscriptionClient::class, function ($mock): void {
            $mock->shouldNotReceive('enabled');
            $mock->shouldNotReceive('transcribe');
        });
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->call($method, $this->teamUrl($name))
            ->assertRedirect(route('two-factor.challenge'));
    }

    #[DataProvider('moduleRoutes')]
    public function test_a_confirmed_agent_cannot_read_a_module_they_were_not_granted(string $name, string $module): void
    {
        $this->actingAs(User::factory()->agent()->withTwoFactor()->create([
            'allowed_modules' => [$module === 'management' ? 'support' : 'management'],
        ]))->withSession(['two_factor.confirmed' => true]);

        $this->get($this->teamUrl($name))->assertForbidden();
    }

    #[DataProvider('moduleRoutes')]
    public function test_a_confirmed_agent_can_read_their_granted_module(string $name, string $module): void
    {
        $this->actingAs(User::factory()->agent()->withTwoFactor()->create([
            'allowed_modules' => [$module],
        ]))->withSession(['two_factor.confirmed' => true]);

        $this->get($this->teamUrl($name))->assertOk();
    }

    #[DataProvider('moduleRoutes')]
    public function test_an_unrestricted_agent_keeps_access(string $name, string $module): void
    {
        $this->actingAs(User::factory()->agent()->create(['allowed_modules' => null]));

        $this->get($this->teamUrl($name))->assertOk();
    }

    #[DataProvider('moduleRoutes')]
    public function test_an_admin_keeps_access_regardless_of_module_grants(string $name, string $module): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create(['allowed_modules' => []]))
            ->withSession(['two_factor.confirmed' => true]);

        $this->get($this->teamUrl($name))->assertOk();
    }

    public function test_a_pending_two_factor_member_can_still_log_out(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->post(route('filament.admin.auth.logout'))->assertRedirect();

        $this->assertGuest();
    }

    public function test_a_pending_member_can_still_request_a_two_factor_code(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->from(route('two-factor.challenge'))->post(route('two-factor.resend'))
            ->assertRedirect(route('two-factor.challenge'));

        Mail::assertSentCount(1);
    }

    public function test_a_confirmed_admin_can_download_the_plugin(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->withSession(['two_factor.confirmed' => true]);

        $this->get(route('agent.plugin.latest'))->assertOk();
    }

    public function test_a_confirmed_agent_still_cannot_download_the_admin_plugin(): void
    {
        $this->actingAs(User::factory()->agent()->withTwoFactor()->create())
            ->withSession(['two_factor.confirmed' => true]);

        $this->get(route('agent.plugin.latest'))->assertForbidden();
    }

    public function test_confirmed_members_can_use_panel_wide_dictation_without_module_grants(): void
    {
        config(['transcription.enabled' => true]);
        $this->actingAs(User::factory()->agent()->withTwoFactor()->create(['allowed_modules' => []]))
            ->withSession(['two_factor.confirmed' => true]);
        $this->mock(TranscriptionClient::class, function ($mock): void {
            $mock->shouldReceive('enabled')->once()->andReturnTrue();
            $mock->shouldReceive('maxBytes')->once()->andReturn(1024);
            $mock->shouldNotReceive('transcribe');
        });

        // Request validation is reached after authentication; missing audio
        // must be reported normally even for a member limited to the dashboard.
        $this->postJson(route('agent.transcribe'))->assertUnprocessable()
            ->assertJsonValidationErrors('audio');
    }

    public static function teamRoutes(): array
    {
        return [
            'signature' => ['GET', 'customer.signature'],
            'customer PDF' => ['GET', 'customer.card-pdf'],
            'task list' => ['GET', 'tasks.print'],
            'support attachment' => ['GET', 'support.attachment'],
            'transcription' => ['POST', 'agent.transcribe'],
            'plugin download' => ['GET', 'agent.plugin.latest'],
            'security keys save' => ['POST', 'integrations.security-keys.fallback'],
            'security keys test' => ['POST', 'integrations.security-keys.test'],
            'push registration' => ['POST', 'push-subscriptions.store'],
            'push removal' => ['DELETE', 'push-subscriptions.destroy'],
        ];
    }

    public static function moduleRoutes(): array
    {
        return [
            'signature' => ['customer.signature', 'management'],
            'customer PDF' => ['customer.card-pdf', 'management'],
            'task list' => ['tasks.print', 'management'],
            'support attachment' => ['support.attachment', 'support'],
        ];
    }

    /** Real private records/files ensure a failed guard would expose content. */
    private function teamUrl(string $name): string
    {
        if (in_array($name, ['customer.signature', 'customer.card-pdf'], true)) {
            Storage::fake('local');
            $customer = Customer::factory()->create([
                'signature_path' => 'signatures/security-test.png',
                'signed_pdf_path' => 'customer-cards/security-test.pdf',
            ]);
            Storage::disk('local')->put($customer->signature_path, 'PRIVATE SIGNATURE');
            Storage::disk('local')->put($customer->signed_pdf_path, '%PDF-1.4 PRIVATE CARD');

            return route($name, $customer);
        }

        if ($name === 'support.attachment') {
            Storage::fake('local');
            $path = 'attachments/security-test.txt';
            Storage::disk('local')->put($path, 'PRIVATE ATTACHMENT');
            $ticket = Ticket::create([
                'customer_id' => Customer::factory()->create()->id,
                'channel' => TicketChannel::Email,
                'subject' => 'Private support request',
                'status' => TicketStatus::Open,
            ]);
            $message = $ticket->messages()->create([
                'direction' => MessageDirection::Inbound,
                'channel' => MessageChannel::Email,
                'body' => 'Private message',
                'author' => MessageAuthor::Customer,
                'attachments' => [['name' => 'private.txt', 'path' => $path, 'disk' => 'local']],
            ]);

            return route($name, ['message' => $message, 'index' => 0]);
        }

        return route($name);
    }
}
