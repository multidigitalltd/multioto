<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this particular charge was actually paid.
 *
 * The fiscal document Linet issues has to state the payment means, and until
 * now that was read from the CUSTOMER at invoicing time. That was true while
 * every subscription followed the customer, and it stopped being true the
 * moment a subscription could be paid another way — and again when a saved card
 * could stand in for a transfer that never arrived. Both directions produce a
 * receipt that names the wrong means:
 *
 *   - a transfer collected by hand, on a customer whose default is a card,
 *     was documented as a card payment;
 *   - a fallback card charge, on a customer whose default is a transfer, was
 *     documented as a transfer.
 *
 * So the rail is recorded on the charge, at the moment the money moves, by the
 * code that moved it. Null on every existing row, and the invoicing path still
 * falls back to the old reading for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
