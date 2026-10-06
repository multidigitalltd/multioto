<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "תודיע לי על כל ליד חדש" — per number, because the person who asked is the
 * person whose phone should ring.
 *
 * `lead_alert_seen` holds the keys (source:id) of leads already accounted for,
 * newest last and capped, so a lead is announced once even though every check
 * reads the whole last day again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->boolean('lead_alerts')->default(false)->index();
            $table->json('lead_alert_seen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->dropIndex(['lead_alerts']);
            $table->dropColumn(['lead_alerts', 'lead_alert_seen']);
        });
    }
};
