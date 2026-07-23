<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer satisfaction (CSAT) for a resolved ticket: when we asked, the 1–5
 * rating the customer gave, an optional free-text comment, and when they rated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedTinyInteger('csat_rating')->nullable()->after('ai_sentiment');
            $table->text('csat_comment')->nullable()->after('csat_rating');
            $table->timestamp('csat_requested_at')->nullable()->after('csat_comment');
            $table->timestamp('csat_rated_at')->nullable()->after('csat_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['csat_rating', 'csat_comment', 'csat_requested_at', 'csat_rated_at']);
        });
    }
};
