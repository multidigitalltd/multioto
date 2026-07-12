<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Optional content check: the page must contain this text, else the
            // site counts as down even on HTTP 200 (catches defacement/blank WSOD).
            $table->string('expected_keyword')->nullable()->after('monitor_url');
            // Cached TLS certificate days-left + last time we alerted about it.
            $table->integer('ssl_days_left')->nullable()->after('expected_keyword');
            $table->timestamp('ssl_alerted_at')->nullable()->after('ssl_days_left');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['expected_keyword', 'ssl_days_left', 'ssl_alerted_at']);
        });
    }
};
