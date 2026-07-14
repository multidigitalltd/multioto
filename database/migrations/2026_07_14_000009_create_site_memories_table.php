<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site memory: durable notes the agent (and the team) keep about a specific
 * site — its quirks, past fixes, credentials-free context — so every action is
 * informed by what we already know about that exact site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_memories');
    }
};
