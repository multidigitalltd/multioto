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

    protected $fillable = [
        'status', 'disk', 'path', 'size_bytes', 'manifest', 'error',
        'user_id', 'run_attempt', 'finished_at', 'restore_status', 'restore_error', 'restored_at',
        'restore_attempt', 'restore_queued_at', 'restore_started_at', 'restore_report',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'restore_status' => BackupStatus::class,
            'manifest' => 'array',
            'restore_report' => 'array',
            'finished_at' => 'datetime',
            'restored_at' => 'datetime',
            'restore_queued_at' => 'datetime',
            'restore_started_at' => 'datetime',
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
