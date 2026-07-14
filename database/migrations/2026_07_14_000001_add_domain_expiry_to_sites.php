<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache the domain's registration expiry (from RDAP/WHOIS) so the team is warned
 * before a domain lapses — the most damaging kind of outage. `domain_alerted_at`
 * mirrors ssl_alerted_at: alert once on entering the warning window, re-arm after
 * the domain is renewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->date('domain_expiry_at')->nullable()->after('ssl_days_left');
            $table->timestamp('domain_alerted_at')->nullable()->after('domain_expiry_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['domain_expiry_at', 'domain_alerted_at']);
        });
    }
};
