<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember the last card-capture Low Profile id opened for a customer, so the
 * team can reconcile it manually ("sync card from Cardcom") if the completion
 * webhook never arrived and the entered card wasn't saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pending_card_lp_id')->nullable()->after('cardcom_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('pending_card_lp_id');
        });
    }
};
