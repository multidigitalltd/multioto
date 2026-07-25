<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TwoFactorChannel;
use App\Enums\UserRole;
use App\Support\TeamModules;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * Team member with access to the Filament admin panel. Registration is
 * disabled and accounts are created manually (app:create-admin), so every
 * user is a trusted team member. Panel access is unconditional; the role only
 * gates which settings/management screens are reachable (see AdminOnly).
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** Whether this member has full (admin) access. */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Whether a one-time code must be confirmed after this member's password.
     * WhatsApp delivery needs a phone on file, so an enabled-but-unreachable
     * WhatsApp setup does not lock the member out.
     */
    public function requiresTwoFactor(): bool
    {
        if (! $this->two_factor_enabled) {
            return false;
        }

        return $this->two_factor_channel !== TwoFactorChannel::Whatsapp || filled($this->phone);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'allowed_modules',
        'phone',
        'two_factor_enabled',
        'two_factor_channel',
    ];

    /**
     * May this user reach screens of the given permission module (a
     * TeamModules key)? Admins always can; agents are limited to the modules
     * an admin granted them — null (never configured) means everything.
     */
    public function canAccessModule(string $module): bool
    {
        if ($this->isAdmin() || ! array_key_exists($module, TeamModules::LABELS)) {
            return true;
        }

        return $this->allowed_modules === null
            || in_array($module, $this->allowed_modules, true);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'allowed_modules' => 'array',
            'two_factor_enabled' => 'boolean',
            'two_factor_channel' => TwoFactorChannel::class,
            'two_factor_expires_at' => 'datetime',
            'two_factor_last_sent_at' => 'datetime',
        ];
    }
}
