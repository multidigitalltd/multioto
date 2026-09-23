<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A name the team told the system to watch for on customer sites.
 *
 * What a rule DOES is worth being exact about, because the obvious assumption
 * is wrong and expensive:
 *
 * - On a site running the companion plugin 1.5.0 or newer, automatic removal is
 *   decided by the plugin's OWN hard-coded list. `wp_guard_purge` takes no
 *   arguments on purpose: nothing sent over the network can widen what a site
 *   deletes, so a panel that is taken over cannot order every customer's admin
 *   accounts destroyed. A rule added here does NOT change that.
 * - What a rule does do on every site is make the panel look for the name and
 *   report it, and on a site whose plugin is too old to guard itself, let the
 *   panel deactivate a matching plugin as it already does for the built-ins.
 *
 * So: rules added here find things and say so. Removal stays with the plugin.
 * The screen says this out loud, because a team that believes otherwise would
 * add a rule and stop looking.
 */
class SecurityRule extends Model
{
    use HasFactory;

    /** An exact WordPress login. */
    public const USER = 'user';

    /** An exact plugin folder slug. */
    public const PLUGIN = 'plugin';

    public const TYPES = [self::USER, self::PLUGIN];

    protected $fillable = ['type', 'value', 'note', 'enabled', 'created_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Normalised on the way in, once, rather than at every comparison.
        // The inventory readers lower-case what they find, so a rule saved as
        // "WP-File-Manager" would quietly never match anything — a rule that
        // looks present on the screen and is absent in effect.
        $normalise = function (self $rule): void {
            $rule->type = in_array($rule->type, self::TYPES, true) ? $rule->type : self::USER;
            $rule->value = mb_strtolower(trim((string) $rule->value));
        };

        static::creating($normalise);
        static::updating($normalise);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<SecurityRule>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * The values in force for one type, lower-cased and unique.
     *
     * Returns [] rather than throwing when the table is not there yet: this is
     * read by the threat matcher, which runs on a schedule, and a deploy that
     * ships the code before its migration must not stop the sweep that protects
     * customers' sites.
     *
     * @return list<string>
     */
    public static function valuesFor(string $type): array
    {
        return rescue(
            fn (): array => array_values(array_unique(
                static::query()->active()->where('type', $type)->pluck('value')->all()
            )),
            [],
            report: false,
        );
    }
}
