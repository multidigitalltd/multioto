<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing opt-out ("הסרה מרשימת דיוור").
 *
 * חוק התקשורת (בזק ושידורים), סעיף 30א requires that a commercial message
 * carry a way to opt out, in the same medium it arrived on, and that the
 * request be honoured. The timestamp is the record of when it was honoured;
 * the channel records where the customer asked, purely for the audit trail.
 *
 * Deliberately scoped to marketing: an opt-out never suppresses a service
 * message — an invoice, a payment demand or a site-down alert is not
 * advertising and the customer still needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('marketing_opt_out_at')->nullable()->index();
            $table->string('marketing_opt_out_channel')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['marketing_opt_out_at', 'marketing_opt_out_channel']);
        });
    }
};
