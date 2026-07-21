<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple contacts per customer (optionally scoped to a specific site), each
 * with a role. Their email / phone / WhatsApp identifiers let an inbound
 * message from a secondary contact still resolve to the right customer, so the
 * ticket is associated correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Optional: a contact tied to one specific site of the customer.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp_jid')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // Inbound-message matching looks contacts up by these identifiers.
            $table->index('email');
            $table->index('phone');
            $table->index('whatsapp_jid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
