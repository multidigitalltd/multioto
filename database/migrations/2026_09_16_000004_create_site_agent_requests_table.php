<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One thing a customer asked the agent to do, from the message to the undo.
 *
 * The row exists before anything changes and outlives the change, because three
 * separate questions are asked of it at three different moments: what did they
 * ask for, what exactly is about to happen, and what was there before. A design
 * that only kept the last of those is one where "תחזיר את זה" has nothing to
 * restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_agent_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_agent_subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Their own words, kept verbatim. When a change turns out wrong,
            // the first question is always what was actually asked for.
            $table->text('message');

            // The provider's id for that message: the same instruction arriving
            // twice must not become two changes.
            $table->string('inbound_message_id')->nullable()->unique();

            // The single operation the agent settled on, from a closed
            // vocabulary — never free-form instructions to run later.
            $table->string('operation', 40)->nullable();
            $table->json('plan')->nullable();

            // Exactly what the customer was shown before they said yes. Kept
            // because the approval was given to THIS text, and an execution
            // that no longer matches it is not the thing they approved.
            $table->text('preview')->nullable();

            // What was there before, so "בטל" has something to put back.
            $table->json('restore')->nullable();

            $table->string('state', 24)->index();
            $table->string('failure_reason', 500)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            // "The one still waiting for this customer's yes" is asked on every
            // inbound message.
            $table->index(['site_agent_subscriber_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_agent_requests');
    }
};
