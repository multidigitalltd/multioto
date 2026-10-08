<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Existing rows deliberately remain legacy until the transition
            // service has checked for unresolved financial attempts under lock.
            $table->string('billing_mode', 16)->nullable()->index();
            $table->dateTime('billing_anchor_at')->nullable();
            $table->dateTime('billing_period_start_at')->nullable();
            $table->dateTime('billing_prepaid_until')->nullable();
            $table->dateTime('billing_stop_at')->nullable();
        });

        Schema::table('site_agent_orders', function (Blueprint $table): void {
            // A delayed callback for a historical prepaid purchase must still
            // fulfil the contract recorded when that order was opened.
            $table->string('billing_mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_orders', fn (Blueprint $table) => $table->dropColumn('billing_mode'));
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['billing_mode']);
            $table->dropColumn(['billing_mode', 'billing_anchor_at', 'billing_period_start_at', 'billing_prepaid_until', 'billing_stop_at']);
        });
    }
};
