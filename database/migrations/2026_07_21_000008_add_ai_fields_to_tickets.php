<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the AI triage on the ticket itself (not only as an internal note), so
 * the summary, topic and customer sentiment can be shown as columns/badges and
 * an angry sender can be surfaced and escalated at a glance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('sla_alerted_at');
            $table->string('ai_topic')->nullable()->after('ai_summary');
            $table->string('ai_sentiment')->nullable()->after('ai_topic');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_topic', 'ai_sentiment']);
        });
    }
};
