<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the raw sender identity on a ticket so an unidentified enquiry still
 * shows who it is from: the name + email for email, the pushname + phone for
 * WhatsApp. Only set when no customer was matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('customer_id');
            $table->string('contact_handle')->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_handle']);
        });
    }
};
