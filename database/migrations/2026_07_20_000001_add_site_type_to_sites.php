<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a site is a store (WooCommerce) or a brochure/presence site. Nullable
 * = "not classified yet"; the agent fills it from the installed plugins, and the
 * team can override it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('sites', 'site_type')) {
                $table->string('site_type')->nullable()->after('environment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            if (Schema::hasColumn('sites', 'site_type')) {
                $table->dropColumn('site_type');
            }
        });
    }
};
