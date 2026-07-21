<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-demand send log: every time a payment demand goes out — the initial
 * request and each reminder — we append {at, channel, template} here, so the
 * team can see exactly when the customer was contacted (demand_sent_at only
 * keeps the LAST contact; this keeps the full history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->json('demand_reminders_log')->nullable()->after('demand_reminder_count');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('demand_reminders_log');
        });
    }
};
