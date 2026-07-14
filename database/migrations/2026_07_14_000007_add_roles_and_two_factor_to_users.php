<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team members now carry a role (admin vs agent) that gates the settings and
 * team-management screens, and can opt into a one-time login code (2FA)
 * delivered by email or WhatsApp. Existing users become admins so nobody is
 * locked out of the panel by the upgrade.
 *
 * Each column is added only if it is missing, so a partially-applied run (which
 * left, say, `role` behind without recording the migration) can be replayed
 * cleanly instead of failing with "column already exists".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('admin')->after('email');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'two_factor_enabled')) {
                $table->boolean('two_factor_enabled')->default(false)->after('phone');
            }
            if (! Schema::hasColumn('users', 'two_factor_channel')) {
                $table->string('two_factor_channel')->default('email')->after('two_factor_enabled');
            }

            // The active challenge: a hashed code, when it expires, when it was
            // last sent (resend throttle) and how many wrong tries so far.
            if (! Schema::hasColumn('users', 'two_factor_code')) {
                $table->string('two_factor_code')->nullable()->after('two_factor_channel');
            }
            if (! Schema::hasColumn('users', 'two_factor_expires_at')) {
                $table->timestamp('two_factor_expires_at')->nullable()->after('two_factor_code');
            }
            if (! Schema::hasColumn('users', 'two_factor_last_sent_at')) {
                $table->timestamp('two_factor_last_sent_at')->nullable()->after('two_factor_expires_at');
            }
            if (! Schema::hasColumn('users', 'two_factor_attempts')) {
                $table->unsignedTinyInteger('two_factor_attempts')->default(0)->after('two_factor_last_sent_at');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            'role',
            'phone',
            'two_factor_enabled',
            'two_factor_channel',
            'two_factor_code',
            'two_factor_expires_at',
            'two_factor_last_sent_at',
            'two_factor_attempts',
        ], fn (string $column): bool => Schema::hasColumn('users', $column)));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
