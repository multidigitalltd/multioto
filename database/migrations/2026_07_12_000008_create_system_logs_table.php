<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-panel system log ("מערכת ועדכונים"): notable operational events — chiefly
 * AI-provider failures — recorded so the team can diagnose issues from the
 * admin panel without shell access to storage/logs. Rows are pruned by the
 * scheduler after the configured retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->default('info')->index(); // info | warning | error
            $table->string('source', 40)->index();                 // e.g. ai, billing, monitoring
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
