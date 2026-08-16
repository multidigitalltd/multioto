<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly maintenance no longer runs on a standing approval.
 *
 * The code stopped honouring one, which is enough to make it inert — but a
 * grant left sitting "enabled" on the approvals screen still SAYS plugin
 * updates run by themselves. Somebody reading that screen would believe the
 * sites are being updated automatically and stop watching for the weekly
 * proposal, which is the opposite of what was asked for. So the grant is
 * switched off where it can be seen.
 *
 * Disabled rather than deleted: its history — how many times it ran and when it
 * was last used — is the record of what happened while it was in force.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('standing_approvals')) {
            return;
        }

        DB::table('standing_approvals')
            ->where('action_key', 'maintenance_update')
            ->update(['enabled' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversed: re-enabling would restore automatic updates on live
        // customer sites, and no rollback of a schema change is a reason to do
        // that. Turn it back on deliberately, in the panel, if ever wanted.
    }
};
