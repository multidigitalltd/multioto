<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two gaps between "we have a licence table" and "we sell plugins".
 *
 * The first is publishing: a release synced from GitHub sat waiting for a click,
 * which made syncing pointless — the whole reason to connect the repository is
 * that tagging a version reaches customers. Publishing on arrival is now the
 * default, and the choice is per plugin.
 *
 * The second is the customer buying without us. An order is the record that
 * survives the round trip to the payment page: what was bought, by whom, and
 * whether the licence for it was issued yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_products', function (Blueprint $table): void {
            // A version tagged on the development repository reaches customers
            // by itself. It is OUR plugin and our release — unlike an update to
            // somebody else's plugin on a customer's managed site, which is a
            // different decision and stays manual.
            $table->boolean('auto_publish')->default(true);
        });

        Schema::create('plugin_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plugin_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            // Filled when the order is fulfilled. Its presence IS the record that
            // the licence was issued, so a webhook arriving twice cannot issue a
            // second one.
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();

            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone')->nullable();

            $table->unsignedSmallInteger('sites_limit')->default(1);
            $table->string('billing_interval')->nullable();
            $table->unsignedInteger('total_agorot');

            $table->string('status')->default('pending');
            // Random, unguessable, and what the "thank you" page is addressed by —
            // so a buyer can see their own order and nobody can walk the others.
            $table->string('reference', 64)->unique();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_orders');

        Schema::table('plugin_products', function (Blueprint $table): void {
            $table->dropColumn('auto_publish');
        });
    }
};
