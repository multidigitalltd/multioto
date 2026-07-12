<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            // Optional free-text note for a manual charge, printed on the Linet
            // invoice line (docDet description). Distinct from `description`,
            // which is the short line name.
            $table->string('invoice_notes', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('invoice_notes');
        });
    }
};
