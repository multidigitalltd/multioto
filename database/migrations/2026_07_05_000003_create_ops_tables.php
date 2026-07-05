<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations: uptime monitoring, incidents, and the webhook audit/idempotency log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->dateTime('checked_at')->index();
            $table->boolean('is_up');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error')->nullable();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('status')->default('open')->index();
            $table->foreignId('broadcast_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('event_type');
            $table->string('external_id')->nullable()->unique()
                ->comment('Provider event id — idempotency key');
            $table->json('payload');
            $table->dateTime('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitor_checks');
    }
};
