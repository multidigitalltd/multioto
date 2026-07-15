<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recorded change can carry the recipe to undo it live: the inverse MCP tool
 * and its arguments. "שחזר" proposes that inverse through the approval gate, so
 * a rollback is itself a manager-approved action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_changes', function (Blueprint $table) {
            if (! Schema::hasColumn('site_changes', 'revert_tool')) {
                $table->string('revert_tool')->nullable()->after('after_state');
            }
            if (! Schema::hasColumn('site_changes', 'revert_arguments')) {
                $table->json('revert_arguments')->nullable()->after('revert_tool');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['revert_tool', 'revert_arguments'],
            fn (string $c): bool => Schema::hasColumn('site_changes', $c),
        ));

        if ($columns !== []) {
            Schema::table('site_changes', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
