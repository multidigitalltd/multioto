<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signup that has been filled in but has no card yet.
 *
 * The public form used to create the customer and then send them to the card
 * page, which meant closing the tab left a customer we had no way to collect
 * from. The details now wait here instead, and the customer is created only
 * once Cardcom hands back a token — so there is no moment at which a customer
 * exists without the card they were required to leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_signups', function (Blueprint $table) {
            $table->id();
            // The opaque reference the customer's own link carries. Random, so
            // a pending signup cannot be found by guessing an id.
            $table->string('token', 64)->unique();

            $table->string('name');
            $table->string('contact_name');
            $table->string('business_number', 20)->nullable();
            $table->string('business_type', 40);
            $table->boolean('vat_exempt')->default(false);
            $table->string('email', 190);
            $table->string('phone', 30);
            $table->string('domain', 190)->nullable();
            $table->string('payment_method', 30);

            // The signed consent, captured with the form and carried onto the
            // customer when one is created.
            $table->string('signature_path')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('security_card_terms_at')->nullable();

            // The Cardcom session this signup is waiting on. The webhook matches
            // back on it, the same way a hosted charge does.
            $table->string('cardcom_lp_id')->nullable()->index();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['completed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_signups');
    }
};
