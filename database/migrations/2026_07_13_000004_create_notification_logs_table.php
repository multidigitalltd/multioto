<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central audit trail of every message sent to a customer (email + WhatsApp):
 * dunning reminders, payment links, welcomes, card-capture links, ticket
 * notifications, agent replies and broadcasts. Read-only history so the team
 * can see exactly what went out, to whom, when, and whether it succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');            // email | whatsapp
            $table->string('type');               // NotificationType
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->default('sent'); // sent | failed
            $table->string('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['customer_id', 'sent_at']);
            $table->index(['type', 'sent_at']);
            $table->index(['channel', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
