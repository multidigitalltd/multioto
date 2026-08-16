<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plugin is sold at more than one price.
 *
 * One site or five, monthly or yearly, and — the one that does not fit a single
 * price field at all — bought outright with no updates. Until now the product
 * carried ONE price, one term and one site count, which made every other way of
 * selling it an argument to have over email.
 *
 * Each plan says three things, and the third is the one that changes what the
 * licence server answers:
 *
 *  · how many sites it covers;
 *  · whether it renews (monthly / yearly) or is paid once;
 *  · **whether updates are included, and for how long.**
 *
 * A perpetual licence with no updates is a real product, not an expired one:
 * the plugin stays working and licensed forever, it simply is never offered a newer
 * version. That is why `includes_updates` lives on the licence and not on a
 * date — an expiry would make the customer's plugin report "פג תוקף" for
 * something they bought outright and still own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plugin_product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('price_agorot');
            // 0 = unlimited, as everywhere else in licensing.
            $table->unsignedSmallInteger('sites_limit')->default(1);
            // Null = paid once. Otherwise the term it renews on.
            $table->string('billing_interval')->nullable();
            /*
             * For a one-off plan only: how many months of updates are included.
             * Null means none — the licence never expires and never receives a
             * newer version. A renewing plan ignores this: its updates last as
             * long as it is paid for.
             */
            $table->unsignedSmallInteger('updates_months')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['plugin_product_id', 'is_active', 'position']);
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->foreignId('plugin_plan_id')->nullable()->after('plugin_product_id')
                ->constrained()->nullOnDelete();
            // Kept on the licence rather than read through the plan: a plan can
            // be edited or deleted years later, and what somebody bought must
            // not change because a price list did.
            $table->boolean('includes_updates')->default(true);
        });

        Schema::table('plugin_orders', function (Blueprint $table): void {
            $table->foreignId('plugin_plan_id')->nullable()->after('plugin_product_id')
                ->constrained()->nullOnDelete();
        });

        // Whatever price a product already carried becomes its first plan, so
        // nothing that was on sale stops being on sale.
        foreach (DB::table('plugin_products')->whereNotNull('price_agorot')->get() as $product) {
            DB::table('plugin_plans')->insert([
                'plugin_product_id' => $product->id,
                'name' => match ($product->billing_interval) {
                    'yearly' => 'מנוי שנתי',
                    'monthly' => 'מנוי חודשי',
                    default => 'רכישה חד-פעמית',
                },
                'price_agorot' => $product->price_agorot,
                'sites_limit' => $product->default_sites_limit ?? 1,
                'billing_interval' => $product->billing_interval,
                'updates_months' => null,
                'is_active' => true,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The product's own price columns go: two places that answer "what does
        // this cost" is one place too many, and the one nobody edits is the one
        // that ends up on an invoice.
        Schema::table('plugin_products', function (Blueprint $table): void {
            $table->dropColumn(['price_agorot', 'billing_interval', 'default_sites_limit']);
        });
    }

    public function down(): void
    {
        Schema::table('plugin_products', function (Blueprint $table): void {
            $table->unsignedInteger('price_agorot')->nullable();
            $table->string('billing_interval')->nullable();
            $table->unsignedSmallInteger('default_sites_limit')->default(1);
        });

        Schema::table('plugin_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plugin_plan_id');
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plugin_plan_id');
            $table->dropColumn('includes_updates');
        });

        Schema::dropIfExists('plugin_plans');
    }
};
