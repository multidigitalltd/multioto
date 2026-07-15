<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's notifications table, used here for Filament's in-panel notification
 * bell — so a new task or a site incident is always visible in the panel, even
 * if WhatsApp/email aren't configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // JSON, not text: Filament queries the bell with where('data->format',
            // 'filament'), which on PostgreSQL needs a json column (the ->> operator
            // is undefined on text). On SQLite/MySQL json maps to text transparently.
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
