<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each number's lead alerts have read up to (Unix time). From plugin
 * 1.8.4 the site is read from this moment on, oldest first, a page at a
 * time — so a burst bigger than one read is followed to its end rather than
 * only its newest fifty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_alert_cursor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->dropColumn('lead_alert_cursor');
        });
    }
};
