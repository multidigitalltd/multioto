<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator command console log: every free-text instruction the team gives
 * the AI agent ("reply to Moshe that we're on it", "clear the cache on site X"),
 * with how it was understood and what it produced (a proposal for approval, a
 * background site investigation, or a request to clarify). This is the audit
 * trail and the on-screen history for the console.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_commands')) {
            return;
        }

        Schema::create('agent_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->text('instruction');
            $table->string('intent')->nullable();
            // interpreted / dispatched / proposed / unclear / failed
            $table->string('outcome')->default('interpreted');
            $table->text('result')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('ticket_id')->nullable();
            $table->foreignId('site_id')->nullable();
            $table->foreignId('pending_action_id')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
