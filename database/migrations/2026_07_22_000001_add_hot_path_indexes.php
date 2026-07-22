<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for hot query paths found in the performance audit. Note: on
 * PostgreSQL, foreignId()->constrained() does NOT create an index by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table) {
            // MonitoringReport / ViewSite / MonitorSiteJob all filter by site
            // and order by checked_at on the fastest-growing table.
            $table->index(['site_id', 'checked_at']);
        });

        Schema::table('charges', function (Blueprint $table) {
            // Portal open-debt query and the reconcile sweep filter by customer.
            $table->index('customer_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            // Knowledge-base retrieval in DraftReplyJob filters by topic+status.
            $table->index(['ai_topic', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', fn (Blueprint $table) => $table->dropIndex(['site_id', 'checked_at']));
        Schema::table('charges', fn (Blueprint $table) => $table->dropIndex(['customer_id']));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropIndex(['ai_topic', 'status']));
    }
};
