<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How THIS subscription is paid, and whether the saved card backs it up.
 *
 * Until now the answer lived on the customer, so a customer was paid for one
 * way or the other and every subscription they held followed. That is not how
 * the business actually works: the same customer can have hosting on a standing
 * order and a one-off retainer on the card, and forcing one answer onto both
 * meant either charging a card the customer did not agree to charge, or leaving
 * a card sitting unused while somebody chased a transfer by hand.
 *
 * Both columns are NULL by default, and null means "as before": inherit the
 * customer's method, and no card fallback. Nothing changes for any existing
 * subscription until somebody sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // null = inherit the customer's payment_method.
            $table->string('payment_method', 30)->nullable()->after('token_id');

            // Days past due before the saved card is charged instead, for a
            // subscription collected by hand. null = never — the fallback is
            // opt-in per subscription, because charging a card that was not
            // supposed to be charged is worse than a late collection.
            $table->unsignedSmallInteger('card_fallback_days')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'card_fallback_days']);
        });
    }
};
