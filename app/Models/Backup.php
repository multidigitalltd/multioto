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
        'user_id', 'finished_at', 'restore_status', 'restore_error', 'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'restore_status' => BackupStatus::class,
            'manifest' => 'array',
            'finished_at' => 'datetime',
            'restored_at' => 'datetime',
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
