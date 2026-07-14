<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team members now carry a role (admin vs agent) that gates the settings and
 * team-management screens, and can opt into a one-time login code (2FA)
 * delivered by email or WhatsApp. Existing users become admins so nobody is
 * locked out of the panel by the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('admin')->after('email');
            $table->string('phone')->nullable()->after('role');

            $table->boolean('two_factor_enabled')->default(false)->after('phone');
            $table->string('two_factor_channel')->default('email')->after('two_factor_enabled');

            // The active challenge: a hashed code, when it expires, when it was
            // last sent (resend throttle) and how many wrong tries so far.
            $table->string('two_factor_code')->nullable()->after('two_factor_channel');
            $table->timestamp('two_factor_expires_at')->nullable()->after('two_factor_code');
            $table->timestamp('two_factor_last_sent_at')->nullable()->after('two_factor_expires_at');
            $table->unsignedTinyInteger('two_factor_attempts')->default(0)->after('two_factor_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'phone',
                'two_factor_enabled',
                'two_factor_channel',
                'two_factor_code',
                'two_factor_expires_at',
                'two_factor_last_sent_at',
                'two_factor_attempts',
            ]);
        });
    }
};
