<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports an owner ordered from the bot: "every morning at 8, yesterday's sales
 * and leads". One row per standing order, per number — the person who asked is
 * the person it goes to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_agent_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_agent_subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // daily | weekly | monthly
            $table->string('frequency', 16);

            // Local time of day, HH:MM, in the panel's timezone.
            $table->string('send_time', 5)->default('08:00');

            // For weekly: 0 (Sunday) … 6 (Saturday).
            $table->unsignedTinyInteger('weekday')->nullable();

            // Which sections: sales, orders, leads, subscriptions.
            $table->json('sections');

            $table->timestamp('next_run_at')->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_agent_report_schedules');
    }
};
