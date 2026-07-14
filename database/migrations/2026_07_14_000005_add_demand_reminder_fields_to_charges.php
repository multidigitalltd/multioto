<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track a payment demand so it can be chased if unpaid. demand_sent_at marks a
 * charge that was SENT to the customer as a demand (as opposed to an immediate
 * charge), records the channel it went out on, and counts how many reminders
 * we've already sent so we stop after the configured maximum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->timestamp('demand_sent_at')->nullable()->after('cardcom_pay_url');
            $table->string('demand_channel')->nullable()->after('demand_sent_at');
            $table->unsignedInteger('demand_reminder_count')->default(0)->after('demand_channel');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['demand_sent_at', 'demand_channel', 'demand_reminder_count']);
        });
    }
};
