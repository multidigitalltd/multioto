<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The team's verdict on one opportunity for one site.
 *
 * The radar rebuilds its findings weekly, so the verdict is stored beside them
 * rather than inside them — otherwise every scan would forget what was already
 * decided and put a dismissed suggestion straight back on the screen.
 */
class OpportunityNote extends Model
{
    use HasFactory;

    public const DISMISSED = 'dismissed';

    public const OFFERED = 'offered';

    protected $fillable = ['site_id', 'key', 'status', 'reason', 'user_id'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a verdict, replacing whatever was decided before.
     *
     * Keyed on site+opportunity so a second opinion overwrites the first rather
     * than leaving two rows to disagree with each other.
     */
    public static function decide(int $siteId, string $key, string $status, ?string $reason = null): self
    {
        return static::updateOrCreate(
            ['site_id' => $siteId, 'key' => $key],
            ['status' => $status, 'reason' => $reason, 'user_id' => auth()->id()],
        );
    }

    /**
     * Every verdict, as [site_id][key] => row — one query for the whole radar,
     * instead of one per opportunity.
     *
     * @return array<int, array<string, self>>
     */
    public static function map(): array
    {
        $map = [];

        foreach (static::with('user:id,name')->get() as $note) {
            $map[$note->site_id][$note->key] = $note;
        }

        return $map;
    }
}
