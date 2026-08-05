<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People copied on a ticket who are not the customer: the customer's bookkeeper,
 * a supplier, a colleague who has to be kept in the loop.
 *
 * A watcher is scoped to ONE ticket on purpose. Being copied on "the invoice
 * question" is not consent to read everything that customer ever wrote to us,
 * and a permanent contact on the customer card would be exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('added_by')->nullable()->comment('Team member who added them');
            $table->timestamps();

            // The same address twice on one ticket would send them every reply twice.
            $table->unique(['ticket_id', 'email']);
            $table->index('email');
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            // Who actually wrote an inbound message when it wasn't the customer.
            // Without it a watcher's reply reads as the customer's own words —
            // which is worse than not showing the reply at all.
            $table->string('sender_label')->nullable()->after('author');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', fn (Blueprint $table) => $table->dropColumn('sender_label'));
        Schema::dropIfExists('ticket_watchers');
    }
};
