<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the generated signed "customer card" PDF (details + signature) that is
 * produced when a customer completes the /join form, kept on the private disk
 * and downloadable from the customer's page by the team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('signed_pdf_path')->nullable()->after('signed_ip');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('signed_pdf_path');
        });
    }
};
