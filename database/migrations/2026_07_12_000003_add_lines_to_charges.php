<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            // Optional multi-line breakdown for the invoice. Each entry is
            // {name, qty, unit_price_agorot}; the line totals sum to
            // total_agorot. When null, the invoice is a single line built from
            // `description` + `total_agorot` (backward compatible).
            $table->json('lines')->nullable()->after('invoice_notes');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('lines');
        });
    }
};
