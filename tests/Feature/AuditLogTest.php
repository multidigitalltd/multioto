<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\TeamAuditLog;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_members_update_is_recorded_with_the_changed_fields(): void
    {
        $user = User::factory()->create(['name' => 'דנה']);
        $this->actingAs($user);

        $customer = Customer::factory()->create(['name' => 'לקוח א']);
        $customer->update(['name' => 'לקוח ב', 'notes' => 'הערה']);

        $entry = AuditLog::where('event', 'updated')
            ->where('auditable_type', Customer::class)
            ->where('auditable_id', $customer->id)
            ->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('דנה', $entry->user_name);
        $this->assertEqualsCanonicalizing(['name', 'notes'], array_keys($entry->changes));
    }

    public function test_system_writes_without_a_signed_in_user_are_not_audited(): void
    {
        // No actingAs — a queue/system write is not a team action.
        Customer::factory()->create();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_a_login_is_recorded(): void
    {
        $user = User::factory()->create();

        Auth::login($user); // fires the Login event

        $this->assertSame(1, AuditLog::where('event', 'login')->where('user_id', $user->id)->count());
    }

    public function test_the_sign_in_flows_bookkeeping_writes_are_not_audited_as_user_updates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // The login flow stores/clears a 2FA one-time code and remember token on
        // the user row — bookkeeping, not a team action. It used to spam the log
        // with a "עודכן משתמש" line before every התחברות entry.
        $user->forceFill([
            'two_factor_code' => bcrypt('123456'),
            'two_factor_expires_at' => now()->addMinutes(5),
            'remember_token' => 'abc123',
        ])->save();

        $this->assertSame(0, AuditLog::where('event', 'updated')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->count());

        // A REAL user change is still audited.
        $user->update(['name' => 'שם חדש']);

        $this->assertSame(1, AuditLog::where('event', 'updated')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->count());
    }

    public function test_sensitive_values_are_redacted(): void
    {
        $this->actingAs(User::factory()->create());

        $site = Site::factory()->create();
        $site->update(['mcp_secret' => 'super-secret-value']);

        $entry = AuditLog::where('auditable_type', Site::class)->where('event', 'updated')->latest('id')->first();
        $this->assertSame('[hidden]', $entry->changes['mcp_secret']);
    }

    public function test_a_two_factor_code_write_produces_no_audit_entry_at_all(): void
    {
        // Stronger than redaction: the 2FA one-time code is login bookkeeping,
        // so its writes are ignored entirely — no row, no hash, nothing.
        $this->actingAs(User::factory()->create());

        $user = User::factory()->create();
        $user->forceFill(['two_factor_code' => bcrypt('123456')])->save();

        $this->assertNull(AuditLog::where('auditable_type', User::class)->where('event', 'updated')->latest('id')->first());
    }

    public function test_a_2fa_required_login_is_audited_only_after_confirmation(): void
    {
        $user = User::factory()->create();
        Auth::login($user); // password step (Login event)

        // Not yet, if this user needs 2FA — otherwise it is recorded immediately.
        $expected = $user->requiresTwoFactor() ? 0 : 1;
        $this->assertSame($expected, AuditLog::where('event', 'login')->count());
    }

    public function test_the_audit_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(TeamAuditLog::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->assertTrue(TeamAuditLog::canAccess());
    }
}
