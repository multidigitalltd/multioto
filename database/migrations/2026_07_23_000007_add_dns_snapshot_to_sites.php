<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Last known DNS state of the site's domain (A/MX/NS records + when
            // it was captured and when a change was last detected), used by the
            // daily DNS-watch to spot hijacks/misconfigurations.
            $table->json('dns_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('dns_snapshot');
        });
    }
};
