<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the catalogue was missing to actually run a plugin business:
 * where the builds come from, and what the thing costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_products', function (Blueprint $table): void {
            // owner/repo of the development repository. Releases published there
            // become releases here, so shipping a version is tagging one.
            $table->string('github_repo')->nullable();
            // Encrypted at rest — a token that can read a private repository is
            // a credential, not a setting.
            $table->text('github_token')->nullable();
            /*
             * What to ship when a GitHub release carries no built zip.
             *
             * Off by default, and that default is the safe one: the repository
             * root is not the plugin. It holds tests, build config, CI files and
             * whatever else lives there, and packing it means all of that is
             * installed on customers' shops. Attaching a built zip to the
             * release is the right answer; this exists for the plugin whose
             * repository IS its distributable, and the screen says so.
             */
            $table->boolean('pack_from_source')->default(false);
            $table->timestamp('github_synced_at')->nullable();
            $table->string('github_error')->nullable();

            // Price of a licence, in agorot. The term decides whether a sale
            // creates a subscription (and renews itself) or stands alone.
            $table->unsignedInteger('price_agorot')->nullable();
            $table->string('billing_interval')->nullable();
            $table->unsignedSmallInteger('default_sites_limit')->default(1);
        });

        Schema::table('plugin_releases', function (Blueprint $table): void {
            // Where this build came from: 'upload' or 'github'. A version that
            // appeared by itself and one somebody uploaded are different facts.
            $table->string('source')->default('upload');
            $table->string('source_ref')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plugin_releases', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_ref']);
        });

        Schema::table('plugin_products', function (Blueprint $table): void {
            $table->dropColumn([
                'github_repo', 'github_token', 'pack_from_source', 'github_synced_at', 'github_error',
                'price_agorot', 'billing_interval', 'default_sites_limit',
            ]);
        });
    }
};
