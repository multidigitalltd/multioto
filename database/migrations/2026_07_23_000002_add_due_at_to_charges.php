<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment demand's "pay by" date: shown to the team, optionally carried onto
 * the Linet proforma, and used by the cash-flow forecast to place the expected
 * inflow on the timeline. Null for ordinary (non-demand) charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->date('due_at')->nullable()->after('demand_channel');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }
};
