<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-off charge collected on Cardcom's hosted page (walk-in customer) is
 * finalised asynchronously by the Low Profile webhook. We match the webhook to
 * its pending charge by the Cardcom LowProfileId — never by ReturnValue, per
 * Cardcom's explicit guidance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('cardcom_low_profile_id')->nullable()->index()->after('cardcom_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('cardcom_low_profile_id');
        });
    }
};
