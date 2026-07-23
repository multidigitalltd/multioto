<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember when the team was alerted (and the customer sent a proactive
 * update link) that a subscription's saved card will expire BEFORE its next
 * charge — so AlertExpiringCardsBeforeChargeJob acts exactly once per card
 * instead of nagging on every daily run. Cleared when a new card is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('card_expiry_alerted_at')->nullable()->after('next_charge_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('card_expiry_alerted_at');
        });
    }
};
