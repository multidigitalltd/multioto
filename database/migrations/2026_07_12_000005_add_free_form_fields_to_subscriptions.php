<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow fully free-form subscriptions — a custom subscription per customer with
 * no fixed plan. plan_id becomes nullable, and when it is null the subscription
 * carries its own name, billing interval and VAT flag; the price lives in the
 * existing price_agorot_override column. When a plan IS set these stay null and
 * the plan's values are used (the model accessors fall back to the plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('name')->nullable()->after('plan_id')
                ->comment('Free-form subscription name; falls back to the plan name when set');
            $table->string('billing_interval')->nullable()->after('name')
                ->comment('Free-form billing interval; falls back to the plan interval');
            $table->boolean('vat_applies')->nullable()->after('billing_interval')
                ->comment('Free-form VAT flag; falls back to the plan flag');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['name', 'billing_interval', 'vat_applies']);
        });
    }
};
