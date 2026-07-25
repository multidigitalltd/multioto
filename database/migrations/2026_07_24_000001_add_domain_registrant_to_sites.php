<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The domain's registered owner (registrant), from the daily RDAP
            // expiry check — shown on the site page next to the expiry date.
            $table->string('domain_registrant', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('domain_registrant');
        });
    }
};
