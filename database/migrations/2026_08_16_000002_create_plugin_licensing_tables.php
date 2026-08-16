<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling our own WordPress plugins: what we sell, what we ship, who bought it,
 * and where it is installed.
 *
 * Four tables, each answering one question:
 *  · plugin_products — what we sell.
 *  · plugin_releases — what a buyer downloads, version by version.
 *  · licenses        — who may run it, on how many sites, until when.
 *  · license_sites   — where it is actually installed, and when it last checked in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_products', function (Blueprint $table): void {
            $table->id();
            // The slug the installed plugin identifies itself by. It is the
            // contract between a shop and us, so it never changes once sold.
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('homepage')->nullable();
            $table->text('description')->nullable();
            // WordPress compatibility, reported to the shop on every update
            // check. Null falls back to the configured defaults.
            $table->string('requires')->nullable();
            $table->string('requires_php')->nullable();
            $table->string('tested')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plugin_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plugin_product_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('zip_path');
            $table->text('changelog')->nullable();
            // Which release is being handed out. Kept as an explicit choice
            // rather than "the highest version": uploading a build and deciding
            // to ship it are two different decisions, and comparing version
            // strings in SQL is a way to ship the wrong one.
            $table->boolean('is_current')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['plugin_product_id', 'version']);
            $table->index(['plugin_product_id', 'is_current']);
        });

        Schema::create('licenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plugin_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // The subscription that renews it, when it renews automatically.
            // A successful charge pushes expires_at forward; nothing else does.
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            // The key itself is NEVER stored. What is stored is its HMAC, which
            // is also what lookups match on — a leaked database hands nobody a
            // working key. The first group is kept so a human can tell two
            // licences apart on screen and on the phone.
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 8);

            $table->string('email')->nullable();
            // 0 = unlimited, mirroring the API contract.
            $table->unsignedSmallInteger('sites_limit')->default(1);
            // Null = never expires.
            $table->date('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['plugin_product_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('license_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            // Normalised (no scheme, no www, no trailing slash) — this is the
            // identity. A shop moving to HTTPS is the same shop, and charging it
            // a second seat for that would be a support ticket, not a sale.
            $table->string('site_url');
            // What the shop actually reported, kept for support: "I changed
            // domain" is answered by seeing what it used to say.
            $table->string('reported_url')->nullable();
            $table->string('version')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['license_id', 'site_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_sites');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('plugin_releases');
        Schema::dropIfExists('plugin_products');
    }
};
