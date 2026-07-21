<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The direct-to-Bit URL Cardcom returns for a hosted charge when the terminal
 * has Bit enabled. Stored so a demand can offer a one-tap "שלם ב-Bit" link
 * (routed through our own cancelable gateway, like the card link).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('cardcom_bit_url', 1024)->nullable()->after('cardcom_pay_url');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('cardcom_bit_url');
        });
    }
};
