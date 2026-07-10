<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_actions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);                    // e.g. ticket_reply
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary');                        // human-readable: what will happen
            $table->json('payload');                        // data needed to execute
            $table->string('proposed_by', 20)->default('ai'); // ai | automation
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_actions');
    }
};
