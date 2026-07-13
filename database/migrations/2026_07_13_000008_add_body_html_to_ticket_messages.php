<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store a SANITIZED rich-HTML rendering of an inbound email alongside the plain
 * `body`. `body` stays the canonical text (used for AI drafting, search and
 * outbound replies); `body_html` is display-only and already run through the
 * allow-list sanitizer, so the conversation view can show bold/links/lists and
 * real paragraph breaks instead of a flattened run of text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->text('body_html')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
