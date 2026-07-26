<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_approvals', function (Blueprint $table): void {
            // One row per pre-approved action kind (e.g. "site_action:wp_cache_flush").
            // While enabled, new proposals of that kind execute immediately instead
            // of waiting for the owner — the owner is notified after the fact.
            $table->id();
            $table->string('action_key', 190)->unique();
            $table->string('label', 190);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_from_action_id')->nullable();
            $table->timestamps();
        });

        Schema::table('pending_actions', function (Blueprint $table): void {
            // Audit trail: which standing approval auto-approved this action.
            $table->foreignId('standing_approval_id')->nullable()->after('proposed_by');
        });
    }

    public function down(): void
    {
        Schema::table('pending_actions', function (Blueprint $table): void {
            $table->dropColumn('standing_approval_id');
        });
        Schema::dropIfExists('standing_approvals');
    }
};
