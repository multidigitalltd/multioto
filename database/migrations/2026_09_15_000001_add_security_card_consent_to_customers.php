<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this customer accepted the security-card arrangement.
 *
 * The arrangement is that a card is held for every customer and charged if the
 * payment they agreed to does not arrive. Charging it requires their agreement,
 * so the agreement is recorded — on the customer, at the moment the terms
 * carrying that clause were shown and ticked.
 *
 * Inferring it instead from "this subscription was created after we changed the
 * policy" is what this column exists to stop: a subscription opened today for a
 * customer who signed up two years ago would have carried the new arrangement,
 * and their card would be charged on terms they were never shown. Null means
 * exactly that — never agreed — and null is what every existing customer has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('security_card_terms_at')->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('security_card_terms_at');
        });
    }
};
