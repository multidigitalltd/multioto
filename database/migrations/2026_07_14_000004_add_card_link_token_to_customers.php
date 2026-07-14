<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-customer nonce carried by every card-capture link. Rotating it revokes
 * all outstanding card links for that customer — the link's signature stays
 * valid, but the controller rejects a token that no longer matches. This is how
 * a card-update link becomes cancelable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('card_link_token')->nullable()->after('pending_card_lp_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('card_link_token');
        });
    }
};
