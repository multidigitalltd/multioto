<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow one-off (manual) charges that are not tied to a subscription: a nullable
 * customer_id and a free-text description, and a nullable subscription_id. The
 * unique (subscription_id, period_start, attempt_number) guard still holds for
 * subscription charges; one-off rows carry a null subscription_id, which SQL
 * treats as distinct so they never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('subscription_id')->constrained()->nullOnDelete();
            $table->string('description')->nullable()->after('failure_reason');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('description');
        });
    }
};
