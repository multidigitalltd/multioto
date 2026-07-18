<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the command log into a chat thread: a row is either the manager's turn
 * ('user') or a system turn ('system') — an approval/rejection result posted
 * back into the conversation. Existing rows are the manager's own commands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->string('role')->default('user')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
