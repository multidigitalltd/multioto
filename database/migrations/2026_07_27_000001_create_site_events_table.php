<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-site, customer-showable finding log: "on 27/07 we detected a new
 * administrator on the site". The team alerts are transient (WhatsApp/email);
 * this is the durable record shown on the site page so the customer can be
 * told exactly what was found and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);          // admin_added, plugin_added, reputation…
            $table->string('severity', 20);      // info | warning | critical
            $table->string('title');
            $table->text('detail')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['site_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
    }
};
