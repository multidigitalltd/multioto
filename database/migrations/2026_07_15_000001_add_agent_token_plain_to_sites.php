<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep an encrypted, retrievable copy of the site's connection (update) token
 * alongside its hash. The hash (`agent_token`) still authenticates the plugin's
 * check-ins; this column lets the panel re-display the token so a manager can
 * always copy the ready-made connection codes straight into the site's plugin,
 * instead of it being shown only once. Same reversible-at-rest protection as
 * `mcp_secret` (encrypted via the model cast, decryptable only with APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'agent_token_plain')) {
                $table->text('agent_token_plain')->nullable()->after('agent_token');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sites', 'agent_token_plain')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('agent_token_plain');
            });
        }
    }
};
