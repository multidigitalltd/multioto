<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omnichannel support: tickets, ticket messages, canned responses, broadcasts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Nullable — unidentified inbound contact until matched');
            $table->string('channel');
            $table->string('subject');
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('assignee')->nullable();
            $table->string('external_thread_ref')->nullable()->index();
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('channel');
            $table->text('body');
            $table->string('external_message_id')->nullable()->unique()
                ->comment('WAHA / email message id — dedupe guard');
            $table->string('author')->default('customer');
            $table->json('attachments')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->string('channel')->default('email');
            $table->json('segment')->nullable()->comment('Customer filter definition');
            $table->string('status')->default('draft')->index();
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('canned_responses');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
