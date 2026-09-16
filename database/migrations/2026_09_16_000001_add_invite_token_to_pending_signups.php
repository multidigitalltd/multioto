<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invite a signup came in on.
 *
 * Part of what makes two submissions the same filing. Without it, a prospect
 * who filed normally and walked away, and then received an exempt invite with
 * the same details, is collapsed onto the earlier filing and sent back to the
 * card page — the exemption a manager just granted could never be used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_signups', function (Blueprint $table) {
            $table->string('invite_token', 64)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('pending_signups', function (Blueprint $table) {
            $table->dropColumn('invite_token');
        });
    }
};
