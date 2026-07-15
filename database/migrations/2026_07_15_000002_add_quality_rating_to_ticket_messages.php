<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A 1–10 quality score the team can give a reply (an AI draft or a sent answer).
 * Feeds the style learner so future drafts lean on the highly-rated replies and
 * avoid the low-rated ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_messages', 'quality_rating')) {
                $table->unsignedTinyInteger('quality_rating')->nullable()->after('body_html');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_messages', 'quality_rating')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->dropColumn('quality_rating');
            });
        }
    }
};
