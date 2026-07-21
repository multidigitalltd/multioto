<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the team pause the automatic reminders for a single payment demand
 * WITHOUT canceling it — the demand stays open and payable, we just stop
 * nudging (e.g. the customer asked for time, or is being handled off-system).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->boolean('demand_reminders_paused')->default(false)->after('demand_reminders_log');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('demand_reminders_paused');
        });
    }
};
