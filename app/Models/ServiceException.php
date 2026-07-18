<?php

namespace App\Models;

use App\Enums\ServiceMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marked span of days where the team works in a reduced capacity or handles
 * only urgent matters. The agent reads the currently-active one to set the
 * right expectation on a new ticket.
 */
class ServiceException extends Model
{
    protected $fillable = ['starts_on', 'ends_on', 'mode', 'note'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'mode' => ServiceMode::class,
        ];
    }

    /** Exceptions whose date span covers the given day (default: today). */
    public function scopeActiveOn(Builder $query, ?Carbon $date = null): Builder
    {
        $day = ($date ?? Carbon::now())->toDateString();

        return $query->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day);
    }
}
