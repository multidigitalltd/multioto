<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a console instruction came from. The console threads a conversation so
 * a follow-up ("and also suspend his site") or an answer to the agent's own
 * question continues the previous turn — until now that thread was keyed on the
 * panel user alone, so instructions arriving from the WhatsApp management group
 * (which have no user) could not be threaded and every turn started cold.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agent_commands', 'source')) {
            return;
        }

        Schema::table('agent_commands', function (Blueprint $table): void {
            // panel | whatsapp
            $table->string('source')->default('panel')->after('user_id');
            $table->index(['source', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['source', 'user_id']);
            $table->dropColumn('source');
        });
    }
};
