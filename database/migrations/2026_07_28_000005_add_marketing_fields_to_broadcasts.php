<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a broadcast as advertising rather than a service announcement.
 *
 * The distinction drives real behaviour, not just a label: an advertising
 * broadcast carries the "פרסומת" heading and the sender's identity that the
 * law requires, and skips every customer who has opted out. A service
 * announcement (planned maintenance, a security notice) is not advertising,
 * so it still reaches opted-out customers — they asked to stop being
 * marketed to, not to stop being told their site will be down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->boolean('is_marketing')->default(true)->index();
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn('is_marketing');
        });
    }
};
