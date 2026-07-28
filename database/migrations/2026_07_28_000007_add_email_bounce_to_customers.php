<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer whose address hard-bounced.
 *
 * Mailing a dead address again is not merely useless: repeated bounces are what
 * mailbox providers score a sender on, so every retry damages delivery for the
 * customers whose addresses DO work. The flag is set from the provider's bounce
 * webhook and cleared the moment someone corrects the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('email_bounced_at')->nullable()->index();
            $table->string('email_bounce_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['email_bounced_at', 'email_bounce_reason']);
        });
    }
};
