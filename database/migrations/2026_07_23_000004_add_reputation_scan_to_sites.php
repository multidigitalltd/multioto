<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The latest domain-reputation check for a site: when it ran and which public
 * spam/malware blocklists (if any) flagged the domain. JSON snapshot, replaced
 * each run — the panel shows it beside the vulnerability scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('reputation_scan')->nullable()->after('vulnerability_scan');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('reputation_scan');
        });
    }
};
