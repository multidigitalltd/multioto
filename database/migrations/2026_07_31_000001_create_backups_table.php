<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per backup archive: where it was written, how big it is, what it
 * holds, and how the last restore from it went.
 *
 * The row is created BEFORE the archive is built, so a run that dies halfway
 * leaves a visible "failed" record rather than silence — the failure mode that
 * matters most for a backup is the one nobody notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backups')) {
            return;
        }

        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            // running | completed | failed
            $table->string('status')->default('running');
            $table->string('disk');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            // Row/file counts and the migration set, for the restore check.
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            // Null for the nightly run; set when a person pressed the button.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finished_at')->nullable();

            // pending | running | completed | failed — the LAST restore attempt
            // from this archive, so the screen can show what happened.
            $table->string('restore_status')->nullable();
            $table->text('restore_error')->nullable();
            $table->timestamp('restored_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
