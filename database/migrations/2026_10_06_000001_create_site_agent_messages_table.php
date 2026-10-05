<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation with the site agent, turn by turn — its short memory.
 *
 * Without it every message is the first one: "ומה עם השנייה?" after a list of
 * orders has nothing to refer to. Kept for days, not months (see
 * siteagent.assistant.transcript_days), because the answers quote the site's
 * own customers — their names, phones and orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_agent_subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            // 'user' — what the owner wrote; 'assistant' — what we answered.
            $table->string('role', 16);
            $table->text('body');
            $table->timestamps();

            // "The last few turns of this conversation" is read on every message.
            $table->index(['site_agent_subscriber_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_agent_messages');
    }
};
