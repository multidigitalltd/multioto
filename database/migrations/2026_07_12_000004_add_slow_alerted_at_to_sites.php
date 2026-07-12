<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Last time we alerted that the site is up but responding slowly.
            // Re-arms once it speeds back up, so there's no repeated nagging.
            $table->timestamp('slow_alerted_at')->nullable()->after('ssl_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('slow_alerted_at');
        });
    }
};
