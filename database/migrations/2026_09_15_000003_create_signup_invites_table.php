<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signup link a manager issued by hand, and the card exemption it may carry.
 *
 * A card is required of everyone; this is how the exception is made, and the
 * reason it is a table rather than a flag on a URL. Waiving the card is a
 * decision somebody makes about a particular customer, so it is recorded like
 * one: who granted it, why, for whom, used once, and expiring on its own.
 *
 * Without a row like this an exemption would be a switch nobody can trace after
 * the fact, on precisely the customers we later cannot collect from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_invites', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();

            // Prefill, so the prospect does not retype what we already know.
            $table->string('name')->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();

            // The exemption itself. Default false: an invite is an ordinary
            // signup link unless somebody deliberately made it otherwise.
            $table->boolean('card_exempt')->default(false);
            $table->string('exempt_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();

            // Single use: an exemption meant for one customer must not become a
            // link that waives the card for everyone it is forwarded to.
            $table->timestamp('used_at')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_invites');
    }
};
