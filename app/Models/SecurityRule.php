<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A name the team told the system to watch for on customer sites.
 *
 * What a rule DOES depends on one flag, and the difference is the whole screen:
 *
 * - **Without `auto_remove`** (the default): the panel looks for the name on every
 *   site and reports it. Nothing is deleted. On a site whose plugin is too old to
 *   guard itself, a matching plugin is also deactivated, as for the built-ins.
 * - **With `auto_remove`**: the name is pushed to each site (`wp_guard_rules`,
 *   plugin 1.7.0+) and the site deletes it the moment it appears — in the same
 *   request that created the account or activated the plugin. On a site running
 *   an older plugin nothing is pushed and the rule behaves as report-only, which
 *   the sweep says out loud rather than leaving the team to assume otherwise.
 *
 * `auto_remove` deliberately gives up a guarantee this file used to make: that
 * nothing sent over the network could widen what a site deletes. What replaces it
 * is enforced in the plugin, where no instruction reaches — never the agent
 * itself, never user #1 from a panel rule, never the last administrator, and a
 * cap on panel-ordered removals per sweep. See Multioto_Agent_Guard.
 *
 * The two built-in names are untouched by any of this: they are hard-coded in the
 * plugin and removed with no instruction from anybody.
 */
class SecurityRule extends Model
{
    use HasFactory;

    /** An exact WordPress login. */
    public const USER = 'user';

    /** An exact plugin folder slug. */
    public const PLUGIN = 'plugin';

    public const TYPES = [self::USER, self::PLUGIN];

    protected $fillable = ['type', 'value', 'note', 'enabled', 'auto_remove', 'created_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'auto_remove' => 'boolean'];
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

    /** @param  Builder<SecurityRule>  $query */
    public function scopeAutoRemoving(Builder $query): Builder
    {
        return $query->where('auto_remove', true);
    }

    /**
     * The values in force for one type, lower-cased and unique.
     *
     * Returns [] rather than throwing when the table is not there yet: this is
     * read by the threat matcher, which runs on a schedule, and a deploy that
     * ships the code before its migration must not stop the sweep that protects
     * customers' sites. The same rescue covers the `auto_remove` column itself,
     * so the window between the code and its migration degrades to report-only
     * rather than to no watching at all.
     *
     * @param  bool  $autoRemovingOnly  only the rules marked for immediate deletion
     * @return list<string>
     */
    public static function valuesFor(string $type, bool $autoRemovingOnly = false): array
    {
        return rescue(
            fn (): array => array_values(array_unique(
                static::query()
                    ->active()
                    ->when($autoRemovingOnly, fn (Builder $q): Builder => $q->autoRemoving())
                    ->where('type', $type)
                    ->pluck('value')
                    ->all()
            )),
            [],
            report: false,
        );
    }
}
