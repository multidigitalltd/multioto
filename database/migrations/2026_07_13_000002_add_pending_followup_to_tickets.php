<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the "waiting for customer" (Pending) clock so a silent ticket can be
 * reminded once, then auto-closed. pending_since is stamped when the ticket
 * enters Pending; pending_reminded_at once its reminder went out. Both clear
 * the moment the ticket leaves Pending (e.g. the customer replies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('pending_since')->nullable()->after('first_response_at');
            $table->dateTime('pending_reminded_at')->nullable()->after('pending_since');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['pending_since', 'pending_reminded_at']);
        });
    }
};
