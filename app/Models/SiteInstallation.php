<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "התקינו לי את זה" — one customer's request that we install the plugin, and
 * the access they handed over so we could.
 *
 * This row holds the most dangerous thing in the database: a way into a
 * customer's WordPress as an administrator. So it is built around getting rid
 * of it rather than keeping it.
 *
 *  - The secret is encrypted at rest and hidden from every array/JSON copy of
 *    the model, so it cannot reach a log, an exception page or an audit payload
 *    by being somewhere a whole model was dumped.
 *  - It is wiped the moment the install is marked done, and swept by
 *    PruneSiteAccessJob when it expires — whichever comes first. A row that has
 *    been sitting here a month is a row that should be empty.
 *  - Reading it is an action a person takes and it is recorded, because "who
 *    looked at this customer's admin login, and when" is a question that has to
 *    have an answer.
 *
 * What we ask for is also deliberately the weaker of the two things a customer
 * might offer: a temporary login link, which expires on their side and can be
 * revoked without changing anything they use. Their own password is accepted
 * because some customers will send it anyway, and a field that refuses it just
 * moves it into an email.
 */
class SiteInstallation extends Model
{
    use HasFactory;

    /** Bought, and we are waiting for them to hand over access. */
    public const AWAITING_ACCESS = 'awaiting_access';

    /** Access is here; this is the queue the team works from. */
    public const READY = 'ready';

    /** Installed and connected. The secret is gone by now. */
    public const INSTALLED = 'installed';

    /** We tried and could not — the note says why. */
    public const FAILED = 'failed';

    /** They changed their mind, or installed it themselves after all. */
    public const CANCELED = 'canceled';

    /** A link from a temporary-login plugin: expires on their side. */
    public const ACCESS_TEMP_LOGIN = 'temp_login';

    /** A username and password they created for us. */
    public const ACCESS_CREDENTIALS = 'credentials';

    public const ACCESS_METHODS = [self::ACCESS_TEMP_LOGIN, self::ACCESS_CREDENTIALS];

    /** States with nothing left for anybody to do. */
    public const CLOSED_STATES = [self::INSTALLED, self::FAILED, self::CANCELED];

    protected $fillable = [
        'customer_id', 'site_id', 'site_agent_order_id', 'domain', 'state',
        'access_method', 'access_secret', 'access_note', 'access_expires_at',
        'access_cleared_at', 'installed_at', 'installed_by',
    ];

    /**
     * Never serialised. This is the column the whole class is built around, and
     * `$model->toArray()` inside a log line is exactly how such a value escapes.
     */
    protected $hidden = ['access_secret'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest: a database copy — a backup, a dump taken to
            // debug something — must not be a list of customers' admin logins.
            'access_secret' => 'encrypted',
            'access_expires_at' => 'datetime',
            'access_cleared_at' => 'datetime',
            'installed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SiteAgentOrder::class, 'site_agent_order_id');
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /** Is there a credential here right now? */
    public function hasAccess(): bool
    {
        return filled($this->access_secret);
    }

    /**
     * Did the customer's own deadline pass?
     *
     * Only ever advisory: a temporary link may have been revoked long before
     * this, and a customer's guess at "it works for a week" is a guess. It
     * decides when we stop holding the value, never whether it still works.
     */
    public function accessExpired(): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->isPast();
    }

    public function isClosed(): bool
    {
        return in_array($this->state, self::CLOSED_STATES, true);
    }

    /**
     * Forget the credential.
     *
     * Called when the install finishes and by the sweep. Writes the moment it
     * happened so the screen can say "נמחקה" instead of showing an empty box
     * that reads exactly like a customer who never sent anything.
     */
    public function clearAccess(): void
    {
        if (! $this->hasAccess()) {
            return;
        }

        $this->forceFill([
            'access_secret' => null,
            'access_cleared_at' => now(),
        ])->save();
    }

    /** Installs waiting for somebody: access handed over, nothing done yet. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('state', self::CLOSED_STATES);
    }

    /** Rows still holding a credential past its stated life. */
    public function scopeAccessStale(Builder $query, int $fallbackDays): Builder
    {
        return $query
            ->whereNotNull('access_secret')
            ->where(fn (Builder $q) => $q
                ->where('access_expires_at', '<', now())
                // No stated expiry is not "keep it forever". A row nobody has
                // touched in weeks has either been done another way or been
                // forgotten, and both end the same: we stop holding it.
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereNull('access_expires_at')
                    ->where('updated_at', '<', now()->subDays($fallbackDays))));
    }

    /** The Hebrew name of each state, for every screen that shows one. */
    public const STATE_LABELS = [
        self::AWAITING_ACCESS => 'ממתין לגישה מהלקוח',
        self::READY => 'מוכן להתקנה',
        self::INSTALLED => 'הותקן',
        self::FAILED => 'לא הצלחנו להתקין',
        self::CANCELED => 'בוטל',
    ];

    public function stateLabel(): string
    {
        return self::STATE_LABELS[$this->state] ?? $this->state;
    }
}
