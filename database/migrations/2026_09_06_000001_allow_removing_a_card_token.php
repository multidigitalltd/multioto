<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a removed card give up its Cardcom token.
 *
 * Removing a card from the panel does not delete anything at Cardcom, and it
 * does not need to: nothing here will ever send that token again. But leaving
 * the credential sitting in the row means a card somebody deliberately removed
 * is still, technically, chargeable — so removal drops it, and the row keeps
 * only what the history needs (brand, last four, expiry, status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_tokens', function (Blueprint $table) {
            $table->string('cardcom_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A removed card has no token to put back, so anything null becomes an
        // empty string rather than blocking the rollback on a NOT NULL it
        // cannot satisfy.
        DB::table('payment_tokens')->whereNull('cardcom_token')->update(['cardcom_token' => '']);

        Schema::table('payment_tokens', function (Blueprint $table) {
            $table->string('cardcom_token')->nullable(false)->change();
        });
    }
};
