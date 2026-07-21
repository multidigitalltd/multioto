<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember when the team was alerted that a ticket breached its first-response
 * SLA, so CheckSlaBreachesJob alerts exactly once per ticket instead of nagging
 * on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_alerted_at')->nullable()->after('pending_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('sla_alerted_at');
        });
    }
};
