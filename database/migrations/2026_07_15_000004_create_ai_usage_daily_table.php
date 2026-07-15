<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day, per-model token usage for the AI layer. Every provider call adds its
 * input/output token counts to today's row for that model, so we can show the
 * team how much the agent has cost so far (tokens × the model's price). A daily
 * rollup keeps the table tiny regardless of call volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_daily')) {
            return;
        }

        Schema::create('ai_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('provider');
            $table->string('model');
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->timestamps();

            $table->unique(['date', 'provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
