<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteResource\Pages\EditSite;
use App\Filament\Resources\SiteResource\Pages\ViewSite;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The MCP-key field on the site form must never absorb the manager's PANEL
 * password: browsers autofill password-type inputs on the panel domain with
 * the saved panel password, which silently overwrote the site's MCP key on
 * every edited site. The field is now a plain input (nothing is ever rendered
 * back into it) and rejects a value identical to the panel password.
 */
class McpSecretFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_password_is_rejected_as_an_mcp_key(): void
    {
        $this->actingAs(User::factory()->create(['password' => Hash::make('הסיסמה-שלי-לפאנל-123')]));
        $site = Site::factory()->create(['mcp_secret' => 'original-secret']);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['mcp_secret' => 'הסיסמה-שלי-לפאנל-123'])
            ->call('save')
            ->assertHasFormErrors(['mcp_secret']);

        $this->assertSame('original-secret', $site->fresh()->mcp_secret);
    }

    public function test_the_rotate_action_mints_a_fresh_random_key(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::factory()->create(['mcp_secret' => 'corrupted-by-autofill']);

        Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
            ->callAction('rotateMcpSecret');

        $fresh = $site->fresh()->mcp_secret;
        $this->assertNotSame('corrupted-by-autofill', $fresh);
        $this->assertSame(40, strlen((string) $fresh));
    }

    public function test_a_real_key_saves_and_an_empty_field_leaves_the_secret_unchanged(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::factory()->create(['mcp_secret' => 'original-secret']);

        // Blank → unchanged.
        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['mcp_secret' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('original-secret', $site->fresh()->mcp_secret);

        // A genuine new key → saved.
        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['mcp_secret' => 'a-genuine-new-key-42'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('a-genuine-new-key-42', $site->fresh()->mcp_secret);
    }
}
