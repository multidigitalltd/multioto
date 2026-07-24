<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Defacement watch: last known homepage content fingerprint (title,
            // normalized-text sample, hash) + the state of the last comparison.
            $table->json('content_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('content_snapshot');
        });
    }
};
