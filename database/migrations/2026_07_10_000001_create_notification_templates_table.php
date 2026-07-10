<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);          // e.g. ticket.received
            $table->string('channel', 20);      // email | whatsapp
            $table->string('subject')->nullable(); // email only
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
