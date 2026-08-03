<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One backup archive written to the external disk.
 *
 * The row exists from the moment the run starts, so an archive that never
 * finished is visible as "failed" instead of simply missing — with backups,
 * the dangerous failure is the silent one.
 */
class Backup extends Model
{
    use HasFactory;

    /**
     * How far a run got towards the destination, recorded as it happens.
     *
     * Both are positive statements, and neither is ever inferred from the
     * absence of the other: a row with NEITHER is one nothing can speak for —
     * it predates the column, or a worker still running the previous code
     * wrote it during a deployment — and it has to be read as possibly having
     * left an archive behind.
     */
    public const UPLOAD_REACHED = 'reached';

    public const UPLOAD_SKIPPED = 'skipped';

    protected $fillable = [
        'status', 'disk', 'path', 'size_bytes', 'manifest', 'error',
        'user_id', 'run_attempt', 'upload_phase', 'finished_at', 'restore_status', 'restore_error', 'restored_at',
        'restore_attempt', 'restore_queued_at', 'restore_started_at', 'restore_report', 'restore_journal',
        'drilled_at', 'drill_report',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'restore_status' => BackupStatus::class,
            'manifest' => 'array',
            'restore_report' => 'array',
            'drill_report' => 'array',
            'finished_at' => 'datetime',
            'restored_at' => 'datetime',
            'restore_queued_at' => 'datetime',
            'restore_started_at' => 'datetime',
            'drilled_at' => 'datetime',
        ];
    }

    /** Archives that actually completed — the only ones worth restoring from. */
    public function scopeRestorable(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Completed);
    }

    /** Who pressed the button; null for the nightly run. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A claim made for a restore that never started running.
     *
     * The queue took the payload and nothing came of it — no worker, or a lost
     * message. Left alone the row would refuse every later attempt for ever and
     * hide its own delete action, so after a while the claim can be taken over.
     * Safe only because the attempt id changes with it: a payload that turns up
     * late finds itself superseded and stops.
     */
    public function restoreClaimExpired(): bool
    {
        $minutes = (int) config('backup.restore_claim_minutes', 30);

        return $this->restore_status === BackupStatus::Running
            && $this->restore_started_at === null
            && $minutes > 0
            && $this->restore_queued_at !== null
            && $this->restore_queued_at->lt(now()->subMinutes($minutes));
    }

    /**
     * A restore that started and then stopped existing.
     *
     * Started claims deliberately do not expire — a restore that got as far as
     * replacing data must never be repeated. But a worker killed before its
     * transaction committed replaced nothing, and left the row on "running"
     * with no way back except editing the database by hand. Three things have
     * to be true to say that safely: the run did not commit (its transaction
     * writes the token, so the absence of one is the absence of a commit), the
     * row has not been touched for longer than an operation could plausibly go
     * without a heartbeat, and nothing is running now.
     */
    public function restoreAbandoned(): bool
    {
        $minutes = max(1, (int) config('backup.operation_window_minutes', 60));

        return $this->restore_status === BackupStatus::Running
            && $this->restore_started_at !== null
            && ! $this->restoreCommitted()
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes($minutes));
    }

    /**
     * Did the attempt this row is on actually replace the data?
     *
     * The token is written inside the replacement transaction, so its presence
     * is the commit. Two subtleties: an EARLIER successful restore leaves its
     * token behind on purpose, so a token belonging to a different attempt says
     * nothing about this one — and a payload from before attempts carried ids
     * has no attempt to compare against, in which case any token on the row is
     * its own, and is proof.
     */
    public function restoreCommitted(): bool
    {
        return $this->restore_journal !== null
            && ($this->restore_attempt === null || $this->restore_journal === $this->restore_attempt);
    }

    public function isAutomatic(): bool
    {
        return $this->user_id === null;
    }

    /** Is the archive still where we left it? */
    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Delete the archive itself. Returns false when it is still there — the
     * destination disks do not throw, so an IAM policy that allows writes but
     * not deletes reports failure only through this value. Dropping the row
     * anyway would leave a file full of customer data at the destination with
     * nothing left to find it by.
     */
    public function deleteArchive(): bool
    {
        if ($this->path === '') {
            return true; // Never got as far as writing one.
        }

        return (bool) rescue(
            fn (): bool => Storage::disk($this->disk)->delete($this->path),
            false,
            report: false,
        );
    }

    public function rowCount(): int
    {
        return (int) ($this->manifest['rows'] ?? 0);
    }

    public function fileCount(): int
    {
        return (int) ($this->manifest['files'] ?? 0);
    }
}
