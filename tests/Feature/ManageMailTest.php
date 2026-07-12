<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_inbound_webhook_url_encodes_a_secret_with_reserved_characters(): void
    {
        $this->actingAs(User::factory()->create());
        config(['billing.email.webhook_secret' => 'a b&c', 'app.url' => 'https://app.test']);

        // The secret is URL-encoded so Postmark calls back with the exact value.
        Livewire::test(ManageMail::class)
            ->assertSee('https://app.test/webhooks/email?secret=a%20b%26c');
    }
}
