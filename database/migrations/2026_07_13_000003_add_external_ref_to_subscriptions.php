<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a subscription a stable external reference (e.g. the WooCommerce
 * subscription id) so a one-off migration import is idempotent per
 * subscription: re-uploading the same export never duplicates, yet a
 * customer with several subscriptions gets all of them. Null for
 * subscriptions created inside the system; unique when present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('external_ref')->nullable()->unique()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
