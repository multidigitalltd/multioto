<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this archive was last PROVEN readable, and what was found.
 *
 * A backup nobody has ever opened is a hope, not a backup: the nightly run can
 * succeed for a year against a bucket that silently truncates, a format that
 * drifted, or a schema that moved on — and every one of those is discovered on
 * the single day it must not be, with the office on fire and the archive in
 * hand. The drill opens the newest one every month and reads it through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            if (! Schema::hasColumn('backups', 'drilled_at')) {
                $table->timestamp('drilled_at')->nullable();
            }

            if (! Schema::hasColumn('backups', 'drill_report')) {
                $table->json('drill_report')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn(['drilled_at', 'drill_report']);
        });
    }
};
