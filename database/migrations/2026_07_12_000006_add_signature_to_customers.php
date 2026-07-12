<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the drawn signature the customer provides on the public signup form as
 * the legal consent record: the private path to the signature image plus the
 * IP address of the filer. terms_accepted_at already stamps the timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('terms_accepted_at');
            $table->string('signed_ip', 45)->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'signed_ip']);
        });
    }
};
